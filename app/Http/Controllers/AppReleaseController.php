<?php

namespace App\Http\Controllers;

use App\Support\AppRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pembaruan aplikasi Android dari server sendiri. Terbuka tanpa login (aplikasi mengecek juga sebelum login, dan alamat unduhan
 * dipakai untuk pemasangan pertama); APK tidak berisi rahasia, hanya alamat server.
 */
class AppReleaseController extends Controller
{
    public function version(): JsonResponse
    {
        $release = AppRelease::current();

        return response()->json($release ? [
            'version_code' => $release['version_code'],
            'version_name' => $release['version_name'],
            'min_version_code' => $release['min_version_code'] ?? 0,
            'notes' => $release['notes'] ?? null,
            'sha256' => $release['sha256'],
            'size' => $release['size'],
            'path' => '/aplikasi/eL-Checklist.apk',
        ] : ['version_code' => 0])->header('Cache-Control', 'no-store');
    }

    public function download(): StreamedResponse
    {
        abort_unless(AppRelease::current(), 404);

        return Storage::disk('local')->download(AppRelease::APK, 'eL-Checklist.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
