<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setiap permintaan: akun & pegawai harus masih aktif (dicabut Administrator atau nonaktif di absensi = langsung keluar),
 * dan sesi tidak boleh melewati batas mutlak sejak login. Batas diam diatur session.lifetime.
 */
class EnsureActiveSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $loggedInAt = (int) $request->session()->get('auth.logged_in_at');
            $expired = now()->timestamp - $loggedInAt > config('session.absolute_lifetime') * 60;

            if ($expired || ! $user->canUseApp()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors(['username' => $expired
                    ? __('Sesi Anda sudah berakhir. Silakan login lagi.')
                    : __('Akses Anda ke aplikasi ini sudah tidak aktif.')]);
            }
        }

        return $next($request);
    }
}
