<?php

namespace App\Console\Commands;

use App\Support\AppRelease;
use Illuminate\Console\Command;

/**
 * Menerbitkan APK Android versi baru: aplikasi di HP staff menawarkan "Perbarui" saat dibuka.
 * Versi dibaca dari output-metadata.json (dibuat Gradle di folder yang sama dengan APK), atau dari --versi-kode / --versi.
 */
class PublishAppRelease extends Command
{
    protected $signature = 'aplikasi:rilis {apk : Path app-release.apk}
        {--versi-kode= : versionCode (angka); kosong = dari output-metadata.json}
        {--versi= : versionName, mis. 1.4.0; kosong = dari output-metadata.json}
        {--wajib-sejak= : versionCode terendah yang masih boleh dipakai; lebih lama = wajib perbarui}
        {--catatan= : Isi singkat yang baru, tampil di aplikasi}';

    protected $description = 'Terbitkan APK Android baru untuk pembaruan dari server sendiri';

    public function handle(): int
    {
        $apk = (string) $this->argument('apk');
        if (! is_file($apk) || file_get_contents($apk, false, null, 0, 2) !== 'PK') {
            $this->error('Berkas APK tidak ditemukan atau bukan APK: '.$apk);

            return self::FAILURE;
        }

        $metaFile = dirname($apk).'/output-metadata.json';
        $built = is_file($metaFile) ? (json_decode((string) file_get_contents($metaFile), true)['elements'][0] ?? []) : [];
        $code = (int) ($this->option('versi-kode') ?: ($built['versionCode'] ?? 0));
        $name = (string) ($this->option('versi') ?: ($built['versionName'] ?? ''));
        if ($code < 1 || $name === '') {
            $this->error('Versi tidak diketahui: salin output-metadata.json bersama APK, atau isi --versi-kode dan --versi.');

            return self::FAILURE;
        }

        $current = AppRelease::current();
        if ($current && $code < $current['version_code'] && ! $this->confirm("Versi terbit sekarang {$current['version_name']} ({$current['version_code']}) lebih baru. Tetap ganti?")) {
            return self::FAILURE;
        }
        $minimum = (int) ($this->option('wajib-sejak') ?: ($current['min_version_code'] ?? 0));

        AppRelease::publish($apk, [
            'version_code' => $code,
            'version_name' => $name,
            'sha256' => hash_file('sha256', $apk),
            'size' => filesize($apk),
            'min_version_code' => min($minimum, $code),
            'notes' => $this->option('catatan') ?: null,
            'published_at' => now()->toIso8601String(),
        ]);
        $this->info("Versi {$name} ({$code}) diterbitkan. Aplikasi di HP menawarkan pembaruan saat dibuka.".($minimum ? " Versi di bawah {$minimum} wajib memperbarui." : ''));

        return self::SUCCESS;
    }
}
