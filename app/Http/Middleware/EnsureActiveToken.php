<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pasangan `EnsureActiveSession` untuk token aplikasi Android: akun dicabut atau pegawai nonaktif = token dihapus, aplikasi diminta login lagi.
 * Batas mutlak (`sanctum.expiration`) dan batas diam (`session.lifetime`) diperiksa saat token dibaca (`AppServiceProvider`).
 */
class EnsureActiveToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->canUseApp()) {
            $user->currentAccessToken()?->delete();

            return response()->json(['message' => __('Akses Anda ke aplikasi ini sudah tidak aktif.')], 401);
        }

        return $next($request);
    }
}
