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

    public function photosPerTask(): int
    {
        return (int) config('checklist.photos.per_task');
    }

    public function put(string $key, string|int|null $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value === null ? null : (string) $value]);
        $this->stored = null;
    }

    private function get(string $key): ?string
    {
        $this->stored ??= Setting::pluck('value', 'key')->all();

        return $this->stored[$key] ?? null;
    }
}
