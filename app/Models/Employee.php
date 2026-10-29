<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Salinan data pegawai dari Sistem Absensi (external_id = id absensi). Tidak menyimpan PIN. */
class Employee extends Model
{
    use HasFactory;

    protected $fillable = ['external_id', 'username', 'name', 'department_id', 'position_title', 'is_active', 'custom_tasks', 'checklist_position_id', 'synced_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'custom_tasks' => 'boolean', 'synced_at' => 'datetime'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Tugas yang dipilih HOD untuk pegawai ini (dipakai bila custom_tasks). */
    public function assignedTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class);
    }

    /** Jabatan checklist pegawai ini (diatur HOD sekali); dipakai saat checklist dibuatkan sekaligus. */
    public function checklistPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'checklist_position_id');
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
