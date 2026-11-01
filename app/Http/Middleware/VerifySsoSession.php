<?php

namespace App\Http\Middleware;

use App\Support\PortalClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Selama SSO aktif, sesi hanya berlaku bila berasal dari portal (mode "wajib") dan masih diakui portal.
 * Aktivitas dilaporkan ke portal tiap menit (batas "tidak aktif" dihitung dari semua sistem); status akun dicek
 * ulang tiap services.sso.check_minutes menit: keluar dari portal, akun dinonaktifkan, atau akses ke sistem ini dicabut
 * membuat pengguna ikut keluar dari sini. Portal sedang mati tidak mengeluarkan siapa pun.
 */
class VerifySsoSession
{
    public const TOKEN_KEY = 'sso.token';

    public const USER_KEY = 'sso.user_id';

    public const CHECKED_KEY = 'sso.checked_at';

    public const REPORTED_KEY = 'sso.reported_at';

    private const REPORT_EVERY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        if (! PortalClient::enabled() || ! Auth::check()) {
            return $next($request);
        }

        $session = $request->session();
        $token = $session->get(self::TOKEN_KEY);

        if (! is_string($token)) {
            // Mode "keduanya": sesi dari username + PIN tidak diperiksa ke portal.
            return PortalClient::allowsPasswordLogin()
                ? $next($request)
                : $this->logout($request, __('Silakan masuk lewat portal.'));
        }

        $now = now()->timestamp;

        if ($now - (int) $session->get(self::REPORTED_KEY) >= self::REPORT_EVERY_SECONDS) {
            if (PortalClient::reportActivity($token) === 'ended') {
                return $this->logout($request, __('Sesi berakhir karena tidak ada aktivitas. Silakan masuk lagi lewat portal.'));
            }

            $session->put(self::REPORTED_KEY, $now);
        }

        if ($now - (int) $session->get(self::CHECKED_KEY) < max(1, (int) config('services.sso.check_minutes')) * 60) {
            return $next($request);
        }

        try {
            $profile = PortalClient::user($token);
        } catch (Throwable $e) {
            Log::warning('Portal SSO tidak dapat dihubungi saat cek sesi: '.$e->getMessage());
            $session->put(self::CHECKED_KEY, $now);

            return $next($request);
        }

        if ($profile === null || $profile['id'] !== (int) $session->get(self::USER_KEY)) {
            return $this->logout($request, __('Sesi portal Anda sudah berakhir. Silakan masuk lagi lewat portal.'));
        }

        $session->put(self::CHECKED_KEY, $now);

        return $next($request);
    }

    private function logout(Request $request, string $message): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Penyimpanan otomatis (JSON) harus tahu sesinya habis, bukan menerima halaman login.
        return $request->expectsJson()
            ? response()->json(['message' => $message], 401)
            : redirect()->route('login')->withErrors(['username' => $message]);
    }
}
