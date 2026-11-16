<?php

namespace App\Support;

use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;

/**
 * Rilis aplikasi Android untuk pembaruan dari server sendiri (tanpa Play Store): satu APK terbaru + keterangannya di disk privat
 * (`android/eL-Checklist.apk`, `android/release.json`). Diisi `php artisan aplikasi:rilis`, dibaca aplikasi lewat `/api/v1/app/versi`,
 * diunduh dari `/aplikasi/eL-Checklist.apk`.
 */
final class AppRelease
{
    public const APK = 'android/eL-Checklist.apk';

    private const META = 'android/release.json';

    /** @return array{version_code: int, version_name: string, sha256: string, size: int, min_version_code: int, notes: ?string, published_at: string}|null */
    public static function current(): ?array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::META) || ! $disk->exists(self::APK)) {
            return null;
        }
        $meta = json_decode((string) $disk->get(self::META), true);

        return is_array($meta) && isset($meta['version_code'], $meta['version_name'], $meta['sha256']) ? $meta : null;
    }

    /** @param array<string, mixed> $meta */
    public static function publish(string $apkPath, array $meta): void
    {
        $disk = Storage::disk('local');
        // APK dulu, keterangan sesudahnya: aplikasi tidak pernah melihat versi baru sebelum berkasnya lengkap.
        $disk->putFileAs('android', new File($apkPath), basename(self::APK));
        $disk->put(self::META, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
