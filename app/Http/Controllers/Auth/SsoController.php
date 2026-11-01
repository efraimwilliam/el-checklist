<?php

namespace App\Http\Controllers\Auth;

use App\Attendance\EmployeeSyncService;
use App\Audit\AuditLogger;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifySsoSession;
use App\Models\Employee;
use App\Models\User;
use App\Support\PortalClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Login lewat Portal SSO. Akun lokal dicocokkan dari username portal (= username Absensi) pada login pertama,
 * lalu dikunci lewat users.sso_user_id. Peran dan departemen tetap diatur di /admin/akses, bukan di portal.
 */
class SsoController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        abort_unless(PortalClient::enabled(), 404);

        $state = Str::random(40);
        $request->session()->put('sso.state', $state);

        return redirect()->away(PortalClient::authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(PortalClient::enabled(), 404);

        $state = $request->session()->pull('sso.state');

        if ($request->filled('error')) {
            return $this->failed(__('Login lewat portal dibatalkan.'));
        }

        if (! is_string($state) || ! hash_equals($state, (string) $request->query('state')) || ! $request->filled('code')) {
            return $this->failed(__('Sesi login sudah kedaluwarsa. Silakan coba lagi.'));
        }

        try {
            $token = PortalClient::exchangeCode((string) $request->query('code'));
            $profile = PortalClient::user($token);
        } catch (Throwable $e) {
            report($e);

            return $this->failed(__('Portal tidak dapat dihubungi. Coba lagi beberapa saat lagi.'));
        }

        if ($profile === null) {
            return $this->failed(__('Akun Anda tidak punya akses ke Checklist Kerjaan di portal.'));
        }

        $user = $this->resolveUser($profile);

        if (! $user) {
            $this->log($request, $profile['username'], 'no_access');

            return $this->failed(__('Akun portal ":username" belum terdaftar sebagai pengguna aktif Checklist Kerjaan. Hubungi HR / Administrator.', ['username' => $profile['username']]));
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put([
            'auth.logged_in_at' => now()->timestamp,
            VerifySsoSession::TOKEN_KEY => $token,
            VerifySsoSession::USER_KEY => $profile['id'],
            VerifySsoSession::CHECKED_KEY => now()->timestamp,
            VerifySsoSession::REPORTED_KEY => now()->timestamp,
        ]);
        $user->forceFill(['last_login_at' => now()])->save();
        $this->log($request, $profile['username'], 'sso_success');

        return redirect()->intended(route('home'));
    }

    /** Dipanggil portal saat pengguna keluar dari semua sistem; lalu kembali ke portal. */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $next = (string) $request->query('next');

        return redirect()->away(PortalClient::url() !== '' && str_starts_with($next, PortalClient::url().'/') ? $next : (PortalClient::url() ?: route('login')));
    }

    /**
     * @param  array{id: int, username: string, name: string, email: ?string}  $profile
     */
    private function resolveUser(array $profile): ?User
    {
        $user = User::where('sso_user_id', $profile['id'])->first()
            ?? User::whereNull('sso_user_id')->whereHas('employee', fn ($employees) => $employees->where('username', $profile['username']))->first()
            ?? $this->systemUser($profile)
            ?? $this->attendanceUser($profile);

        if (! $user || ! $user->canUseApp()) {
            return null;
        }

        if ($user->sso_user_id === null) {
            $user->forceFill(['sso_user_id' => $profile['id']])->saveQuietly();
        }

        return $user;
    }

    /**
     * Portal sudah memberi akses Checklist, tapi akun Checklist belum ada: pegawai dicari di data Absensi terbaru.
     * Belum ada di salinan lokal → sinkron dari Absensi dulu. Ditemukan dan aktif = akun Staff; sisanya ditolak.
     */
    private function attendanceUser(array $profile): ?User
    {
        $find = fn () => Employee::where('username', $profile['username'])->whereNotNull('external_id')->first();

        $employee = $find();
        if (! $employee) {
            try {
                app(EmployeeSyncService::class)->sync();
            } catch (Throwable $e) {
                report($e);

                return null;
            }
            $employee = $find();
        }

        // Akun yang sudah ada (mis. terikat ke akun Portal lain) tidak boleh diambil lewat jalur ini.
        if (! $employee || ! $employee->is_active || $employee->user()->exists()) {
            return null;
        }

        return User::create(['employee_id' => $employee->id, 'role' => Role::Staff, 'is_active' => true]);
    }

    /**
     * Akun sistem yang diizinkan di SSO_SYSTEM_ACCOUNTS (mis. "admin"): dibuatkan data pegawai tanpa external_id
     * dan akun Administrator, supaya bisa masuk Checklist seperti aplikasi lain. Peran selanjutnya diatur di /admin/akses.
     */
    private function systemUser(array $profile): ?User
    {
        if (! in_array($profile['username'], config('services.sso.system_accounts'), true)) {
            return null;
        }

        $employee = Employee::firstOrCreate(
            ['username' => $profile['username'], 'external_id' => null],
            ['name' => $profile['name'] !== '' ? $profile['name'] : $profile['username'], 'is_active' => true],
        );

        return User::firstOrCreate(['employee_id' => $employee->id], ['role' => Role::Admin, 'is_active' => true]);
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['username' => $message]);
    }

    /** Hasil login dicatat tanpa token. */
    private function log(Request $request, string $username, string $outcome): void
    {
        Log::channel('auth')->info('login', ['username' => $username, 'ip' => $request->ip(), 'outcome' => $outcome]);
        AuditLogger::record('login.'.$outcome, null, null, null, null, $username);
    }
}
