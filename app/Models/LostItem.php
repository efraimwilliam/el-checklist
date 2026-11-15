<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Barang tertinggal (lost & found). Dicatat dari obrolan (menu lampiran / "/t", atau foto berketerangan "1203 ... tertinggal")
 * dan statusnya diperbarui dari kartu di obrolan; tidak punya menu sendiri. Foto privat di `lost-items/`.
 */
class LostItem extends Model
{
    public const STATUS_STORED = 'disimpan';

    public const STATUSES = ['disimpan', 'diambil', 'dikirim', 'dilepas'];

    protected $fillable = ['room_id', 'location', 'description', 'photo_path', 'stored_at', 'status', 'resolution_note', 'found_by', 'found_at', 'resolved_by', 'resolved_at', 'source'];

    protected function casts(): array
    {
        return ['found_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return ['disimpan' => __('Disimpan'), 'diambil' => __('Diambil tamu'), 'dikirim' => __('Dikirim ke tamu'), 'dilepas' => __('Tidak diambil')];
    }

    /** Nada pil status (komponen x-ui.badge). */
    public function tone(): string
    {
        return match ($this->status) {
            'disimpan' => 'ongoing',
            'diambil', 'dikirim' => 'done',
            default => 'neutral',
        };
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_STORED;
    }

    /** "Kamar 1203" atau nama lokasi lain. */
    public function placeTitle(): string
    {
        return $this->room_id ? __('Kamar').' '.$this->room?->number : (string) ($this->location ?: __('Lokasi tidak dicatat'));
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function finder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'found_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
