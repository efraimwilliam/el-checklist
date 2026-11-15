<?php

namespace App\Checklists;

use App\Models\Setting;

/**
 * Pengaturan checklist yang bisa diubah Administrator. Nilai awal dari config/checklist.php; yang tersimpan di tabel settings menang.
 * Didaftarkan sebagai "scoped" supaya hanya sekali membaca database per permintaan (daftar checklist memanggilnya berkali-kali).
 */
class ChecklistSettings
{
    public const REQUIREMENTS = [
        'optional' => 'Opsional',
        'pending' => 'Wajib untuk tugas Pending',
        'all' => 'Wajib untuk semua tugas',
    ];

    /** @var array<string, string|null>|null */
    private ?array $stored = null;

    /** optional | pending | all */
    public function photoRequirement(): string
    {
        $value = $this->get('photo_requirement');

        return array_key_exists((string) $value, self::REQUIREMENTS) ? $value : config('checklist.photos.requirement');
    }

    /** Hari sebelum foto dihapus otomatis; 0 = tidak pernah. */
    public function photoRetentionDays(): int
    {
        $value = $this->get('photo_retention_days');

        return is_numeric($value) ? max(0, (int) $value) : (int) config('checklist.photos.retention_days');
    }

    /** Isi checklist untuk hari yang akan datang (Administrator yang mengaktifkan). Atur tugas ke depan selalu boleh. */
    public function futureFillAllowed(): bool
    {
        return $this->get('checklist_future_fill') === '1';
    }

    /**
     * Fitur yang bisa dinyalakan / dimatikan Administrator (Pengaturan > Fitur): kunci => [nama, keterangan, bawaan].
     * Checklist, Dashboard, dan Riwayat checklist adalah inti aplikasi, jadi tidak ada di sini. Mematikan fitur menyembunyikan menunya dan
     * menutup rutenya (404, lihat EnsureFeatureEnabled); datanya tetap tersimpan dan kembali saat dinyalakan.
     */
    public const FEATURES = [
        'permintaan' => ['Permintaan tamu', 'Menu Permintaan: Front Office mencatat permintaan tamu (air mineral, handuk, laundry, MUR), departemen tujuan mengambil dan menyelesaikannya.', true],
        'kendala' => ['Kendala dan Peta kamar', 'Menu Kendala, track record kamar, dan Peta kamar di Laporan. Data kamar dan denah tetap bisa diatur.', true],
        'chronology' => ['Chronology Report', 'Menu Chronology Report (laporan kejadian dengan tamu) dan ringkasannya di Dashboard.', true],
        'obrolan' => ['Obrolan pekerjaan', 'Menu Obrolan dan tombol obrolan melayang, juga dari halaman kendala dan checklist. HR dan Administrator dapat membaca semua percakapan.', false],
        'laporan' => ['Laporan', 'Menu Laporan: Ringkasan, Masalah & Pending, Parameter, Bulanan, dan Data. Dashboard tetap ada; snapshot bulanan tetap dihitung tiap malam.', true],
    ];

    /** Kunci lama yang tetap dipakai (obrolan sudah punya saklar sebelum ada halaman Fitur). */
    private const FEATURE_SETTING_KEYS = ['obrolan' => 'chat_enabled'];

    public static function featureSettingKey(string $feature): string
    {
        return self::FEATURE_SETTING_KEYS[$feature] ?? 'feature_'.$feature;
    }

    /** Fitur menyala? Belum pernah diatur = nilai bawaan di FEATURES; kunci yang tidak dikenal dianggap menyala. */
    public function featureEnabled(string $feature): bool
    {
        if (! isset(self::FEATURES[$feature])) {
            return true;
        }
        $value = $this->get(self::featureSettingKey($feature));

        return $value === null ? self::FEATURES[$feature][2] : $value === '1';
    }

    /**
     * Ketik bebas di obrolan (Pengaturan > Fitur > "Ketik bebas di obrolan"): pesan seperti "1206 req 2 mineral water" dibaca
     * menjadi permintaan / saran kendala (App\Chat\QuickEntry). Semua menyala secara bawaan; hanya berlaku bila Obrolan menyala.
     */
    public const QUICK_ENTRY = [
        'quick_entry' => ['Ketik bebas tercatat', 'Pesan di obrolan seperti "1206 req 2 mineral water" dibaca dan diberi pratinjau sebelum dikirim. Kalau mati, semua pesan terkirim sebagai teks biasa; menu lampiran dan perintah /p /k /c tetap ada.'],
        'quick_requests' => ['Baca permintaan tamu', 'Pesan yang terbaca sebagai permintaan langsung tercatat saat dikirim (perlu fitur Permintaan tamu).'],
        'quick_issues' => ['Sarankan kendala', 'Pesan yang terbaca sebagai kendala diberi tombol "Catat kendala" yang membuka form Kendala terisi (perlu fitur Kendala).'],
    ];

    public function quickEntry(string $key): bool
    {
        return $this->get($key) !== '0' && ($key === 'quick_entry' || $this->get('quick_entry') !== '0');
    }

    /** Tujuan bawaan permintaan "Lainnya" (kini diatur di jenis Lainnya, tabel guest_request_kinds; kosong = Housekeeping). */
    public function quickOtherDepartmentId(): ?int
    {
        return \App\Models\GuestRequest::kinds()->all()->get('other')?->department_id;
    }

    /** Obrolan pekerjaan aktif? Mati secara bawaan; hanya Administrator yang menyalakan. Data tetap tersimpan saat dimatikan. */
    public function chatEnabled(): bool
    {
        return $this->featureEnabled('obrolan');
    }

    public const MAX_DAYS_AHEAD = 60;

    public function photosPerTask(): int
    {
        return (int) config('checklist.photos.per_task');
    }

    public function put(string $key, string|int|null $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value === null ? null : (string) $value]);
        $this->stored = null;
    }

    /** Nilai mentah tersimpan (null = belum pernah diatur). */
    public function get(string $key): ?string
    {
        $this->stored ??= Setting::pluck('value', 'key')->all();

        return $this->stored[$key] ?? null;
    }
}
