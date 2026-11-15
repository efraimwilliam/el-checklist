<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu jenis permintaan tamu (Air mineral, Handuk, ...). `key` tetap selamanya; `department_id` kosong = Housekeeping. */
class GuestRequestKind extends Model
{
    protected $fillable = ['key', 'label', 'has_quantity', 'department_id', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['has_quantity' => 'boolean', 'is_active' => 'boolean'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
