<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Salinan data pegawai dari Sistem Absensi (external_id = id absensi). Tidak menyimpan PIN. */
class Employee extends Model
{
    use HasFactory;

    protected $fillable = ['external_id', 'username', 'name', 'department_id', 'position_title', 'is_active', 'synced_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'synced_at' => 'datetime'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
