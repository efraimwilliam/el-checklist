<?php

namespace App\Http\Controllers\Auth;

use App\Audit\AuditLogger;
use App\Attendance\AttendanceApiService;
use App\Attendance\AuthOutcome;
use App\Attendance\AuthResult;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Login dengan username + PIN Sistem Absensi. PIN diteruskan ke API absensi dari server ini lalu dibuang;
 * setelah berhasil, aplikasi memakai sesi Laravel sendiri (tanpa "ingat saya").
 */
class AttendanceLoginController extends Controller
{
    /** Gagal sebanyak ini (per username + IP) → login dikunci FAILURE_DECAY detik. */
    public const MAX_FAILURES = 5;

    public const FAILURE_DECAY = 900;

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, AttendanceApiService $api): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'pin' => ['required', 'string', 'max:20'],
        ]);
        $username = mb_strtolower(trim($data['username']));
        $key = 'login-failures:'.sha1($username.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            $this->log($request, $username, 'throttled');

            return $this->failed(__('Terlalu banyak percobaan login. Coba lagi dalam :n menit.', ['n' => (int) ceil(RateLimiter::availableIn($key) / 60)]));
        }

        $result = $api->authenticate($username, $data['pin']);

        if (! $result->succeeded()) {
            // Gangguan API bukan kesalahan pegawai: tidak menambah hitungan gagal, dan PIN tidak pernah diterima dari data lama.
            if (! $result->outcome->isServiceProblem()) {
                RateLimiter::hit($key, self::FAILURE_DECAY);
            }
            $this->log($request, $username, $result->outcome->value);

            return $this->failed($this->message($result));
        }

        $employee = $this->resolveEmployee($result->employee);
        $user = $employee->user;
        if (! $user || ! $user->canUseApp()) {
            $this->log($request, $username, 'no_access');

            return $this->failed(__('Anda belum memiliki akses ke aplikasi ini. Hubungi HR / Administrator.'));
        }

        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.logged_in_at', now()->timestamp);
        $user->forceFill(['last_login_at' => now()])->save();
        $this->log($request, $username, 'success');

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        AuditLogger::record('logout');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Pegawai lokal dari profil yang dikembalikan absensi (dicocokkan lewat id absensi), sekaligus memperbarui datanya.
     *
     * @param  array{id: int, username: string, name: string, department_id: ?int, position: ?string, is_active: bool}  $profile
     */
    private function resolveEmployee(array $profile): Employee
    {
        $employee = Employee::firstOrNew(['external_id' => $profile['id']]);
        $departmentId = $profile['department_id'] ? Department::where('external_id', $profile['department_id'])->value('id') : null;
        $employee->fill([
            'username' => $profile['username'],
            'name' => $profile['name'],
            'position_title' => $profile['position'],
            'department_id' => $departmentId ?? $employee->department_id,
            'is_active' => $profile['is_active'],
        ])->save();

        return $employee->load('user');
    }

    private function message(AuthResult $result): string
    {
        return match ($result->outcome) {
            AuthOutcome::Locked => __('Terlalu banyak PIN salah. Coba lagi dalam :n menit, atau reset PIN di aplikasi absensi.', ['n' => max(1, (int) ceil(($result->retryAfter ?? 900) / 60))]),
            AuthOutcome::PinNotSet => __('Anda belum memiliki PIN. Minta HR/HOD membuka izin buat PIN, lalu buat PIN di aplikasi absensi. Setelah itu kembali ke aplikasi ini.'),
            AuthOutcome::PinDisabled => __('Login dengan PIN sedang tidak tersedia. Hubungi HR.'),
            AuthOutcome::Timeout, AuthOutcome::Unavailable, AuthOutcome::Malformed, AuthOutcome::NotConfigured => __('Sistem absensi sedang tidak dapat dihubungi. Coba lagi beberapa saat.'),
            // Username/PIN salah dan pegawai nonaktif sengaja satu pesan umum.
            default => __('Username atau PIN tidak sesuai.'),
        };
    }

    private function failed(string $message): RedirectResponse
    {
        return back()->onlyInput('username')->withErrors(['username' => $message]);
    }

    /** Hasil login dicatat tanpa PIN. */
    private function log(Request $request, string $username, string $outcome): void
    {
        Log::channel('auth')->info('login', ['username' => $username, 'ip' => $request->ip(), 'outcome' => $outcome]);
        AuditLogger::record('login.'.$outcome, null, null, null, null, $username);
    }
}
