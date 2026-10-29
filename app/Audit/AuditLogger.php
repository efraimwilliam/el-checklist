<?php

namespace App\Audit;

use App\Models\AuditLog;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Pencatat log aplikasi. Pengguna diambil dari sesi (kosong untuk perintah terjadwal → "Sistem").
 * Kegagalan mencatat tidak boleh menggagalkan aksi pengguna, jadi error hanya dicatat ke log biasa.
 */
final class AuditLogger
{
    /** @param  array<string, array{0: mixed, 1: mixed}>|null  $changes  field => [lama, baru] */
    public static function record(string $action, ?string $subjectType = null, ?int $subjectId = null, ?string $label = null, ?array $changes = null, ?string $username = null): void
    {
        try {
            $user = Auth::user();
            AuditLog::create([
                'occurred_at' => now(),
                'user_id' => $user?->id,
                'user_name' => $username ?? $user?->name() ?? 'Sistem',
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'subject_label' => $label !== null ? mb_substr($label, 0, 250) : null,
                'changes' => $changes ? array_map(fn (array $pair) => [self::plain($pair[0]), self::plain($pair[1])], $changes) : null,
                'ip' => request()?->ip(),
            ]);
        } catch (\Throwable $error) {
            Log::error('Log aplikasi gagal dicatat: '.$error->getMessage(), ['action' => $action]);
        }
    }

    /** Nilai yang bisa disimpan sebagai JSON dan dibaca manusia. */
    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? 'ya' : 'tidak',
            is_array($value), is_object($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => $value,
        };
    }
}
