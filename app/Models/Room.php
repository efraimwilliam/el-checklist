<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu kamar hotel (data master untuk pencatatan kendala kamar). */
class Room extends Model
{
    protected $fillable = ['number', 'floor', 'building', 'type', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Kotak kamar ini di denah (dihapus otomatis bersama kamarnya). */
    public function planAreas(): HasMany
    {
        return $this->hasMany(FloorPlanArea::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(RoomIssue::class);
    }

    /** Teks untuk pilihan dan tampilan: "305 · Lantai 3". */
    public function label(): string
    {
        return $this->number.($this->floor ? ' · '.__('Lantai').' '.$this->floor : '');
    }
}
