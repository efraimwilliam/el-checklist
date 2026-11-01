<?php

namespace App\Http\Controllers;

use App\Attendance\AttendanceApiService;
use App\Attendance\AttendanceUnavailable;
use App\Audit\AuditLogger;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Ubah username dan password dashboard akun di Sistem Absensi, dari Checklist. Penyimpanannya selalu di Absensi;
 * Checklist hanya meneruskan isian (PIN atau password pengubah ikut, untuk diperiksa di sana) dan tidak menyimpan apa pun.
 */
class AccountController extends Controller
{
    public function editOwn(Request $request): View
    {
        if ($request->user()->hasLocalPassword()) {
            return view('account.local', ['user' => $request->user()]);
        }

        return $this->form($request->user()->employee, self: true);
    }

    public function updateOwn(Request $request, AttendanceApiService $api): RedirectResponse
    {
        if ($request->user()->hasLocalPassword()) {
            return $this->saveLocalPassword($request);
        }

        return $this->save($request, $request->user()->employee, $api, self: true);
    }

    /**
     * Password akun sistem yang disimpan di Checklist (checklist:admin-lokal). Tidak menghubungi Absensi.
     * Password saat ini wajib; 5 kali salah → terkunci 15 menit (sama seperti login).
     */
    private function saveLocalPassword(Request $request): RedirectResponse
    {
        $user = $request->user();
        $key = 'local-password|'.$user->id;

        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ], [], [
            'current_password' => __('password saat ini'),
            'password' => __('password baru'),
        ]);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['current_password' => __('Terlalu banyak percobaan salah. Coba lagi dalam :n menit.', ['n' => (int) ceil(RateLimiter::availableIn($key) / 60)])]);
        }

        if (! Hash::check($data['current_password'], $user->password)) {
            RateLimiter::hit($key, 900);

            return back()->withErrors(['current_password' => __('Password saat ini salah.')]);
        }

        if (Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => __('Password baru harus berbeda dari yang lama.')]);
        }

        RateLimiter::clear($key);
        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        // Sesi lain pada akun ini dikeluarkan; sesi yang sedang dipakai tetap masuk.
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        AuditLogger::record('password.changed', 'User', $user->id, $user->name());

        return redirect()->route('profile.edit')->with('status', __('Password Checklist sudah diganti.'));
    }

    /** HR / Administrator saja (gate manage-tasks); Absensi tetap memeriksa batas kewenangannya sendiri. */
    public function editOther(Request $request, Employee $employee): View|RedirectResponse
    {
        if ($employee->is($request->user()->employee)) {
            return redirect()->route('profile.edit');
        }

        return $this->form($employee, self: false);
    }

    public function updateOther(Request $request, Employee $employee, AttendanceApiService $api): RedirectResponse
    {
        if ($employee->is($request->user()->employee)) {
            return redirect()->route('profile.edit');
        }

        return $this->save($request, $employee, $api, self: false);
    }

    private function form(?Employee $employee, bool $self): View
    {
        abort_if(! $employee, 404);

        return view('account.edit', [
            'employee' => $employee,
            'self' => $self,
            'dashboard' => (bool) $employee->user?->role->usesDashboardPassword(),
        ]);
    }

    private function save(Request $request, ?Employee $employee, AttendanceApiService $api, bool $self): RedirectResponse
    {
        abort_if(! $employee, 404);
        $dashboard = (bool) $employee->user?->role->usesDashboardPassword();
        $back = $self ? route('profile.edit') : route('team.account.edit', $employee);

        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*[a-zA-Z0-9]$/'],
            'password' => [$dashboard ? 'nullable' : 'prohibited', 'string', 'min:8', 'max:255', 'confirmed'],
            'secret' => ['required', 'string', 'max:255'],
        ], [], [
            'username' => __('username'),
            'password' => __('password baru'),
            'secret' => __('PIN atau password'),
        ]);

        $actor = $request->user()->employee;
        $username = mb_strtolower(trim($data['username']));
        $payload = [
            'actor_username' => $actor->username,
            'actor_secret' => $data['secret'],
            'target_username' => $employee->username,
        ];
        if ($username !== $employee->username) {
            $payload['username'] = $username;
        }
        if (filled($data['password'] ?? null)) {
            $payload['password'] = $data['password'];
        }

        try {
            $result = $api->updateAccount($payload);
        } catch (AttendanceUnavailable) {
            return back()->withInput($request->except(['secret', 'password', 'password_confirmation']))
                ->withErrors(['secret' => __('Sistem absensi sedang tidak dapat dihubungi. Coba lagi beberapa saat.')]);
        }

        if (! $result['ok']) {
            return back()->withInput($request->except(['secret', 'password', 'password_confirmation']))
                ->withErrors([$this->field($result['reason']) => $this->message($result)]);
        }

        // Sumber kebenaran ada di Absensi; salinan lokal (dipakai daftar & login berikutnya) ikut diperbarui.
        $employee->forceFill(['username' => $result['employee']['username']])->save();

        return redirect($back)->with('status', __('Akun disimpan ke Sistem Absensi.'));
    }

    private function field(string $reason): string
    {
        return match ($reason) {
            'invalid_credentials', 'locked', 'inactive' => 'secret',
            'password_needs_current', 'password_same', 'password_not_applicable' => 'password',
            default => 'username',
        };
    }

    /** @param  array{reason: string, message?: string, retry_after?: int}  $result */
    private function message(array $result): string
    {
        return match ($result['reason']) {
            'invalid_credentials' => __('PIN atau password tidak sesuai.'),
            'locked' => __('Terlalu banyak percobaan salah. Coba lagi dalam :n menit.', ['n' => max(1, (int) ceil(($result['retry_after'] ?? 900) / 60))]),
            'inactive' => __('Akun Anda nonaktif di Sistem Absensi.'),
            'forbidden' => __('Anda tidak boleh mengubah akun ini.'),
            'target_not_found' => __('Akun tidak ditemukan di Sistem Absensi.'),
            'password_not_applicable' => __('Akun ini tidak memakai password dashboard.'),
            'password_needs_current' => __('Untuk mengganti password dashboard, isi password lama di kolom PIN atau password.'),
            'password_same' => __('Password baru harus berbeda dari yang lama.'),
            'username_problem' => $result['message'] ?? __('Username tidak bisa dipakai.'),
            default => __('Data belum lengkap atau tidak valid.'),
        };
    }
}
