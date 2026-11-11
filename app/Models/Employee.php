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

    protected $fillable = ['external_id', 'username', 'name', 'department_id', 'position_title', 'photo_version', 'is_active', 'custom_tasks', 'checklist_position_id', 'checklist_title', 'synced_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'custom_tasks' => 'boolean', 'synced_at' => 'datetime'];
    }

    /**
     * Jabatan checklist yang perlu dicek HOD: jabatan di Absensi berubah sejak jabatan checklist dikonfirmasi,
     * atau jabatan checklist-nya bukan dari departemen pegawai ini lagi (pindah departemen). Tidak mengubah apa pun.
     *
     * @return array{type: 'title', from: string, to: ?string}|array{type: 'department', position: string}|null
     */
    public function positionReview(): ?array
    {
        if (! $this->checklist_position_id) {
            return null;
        }

        $position = $this->checklistPosition;
        if ($position && $this->department_id && $position->department_id !== $this->department_id) {
            return ['type' => 'department', 'position' => $position->name];
        }

        $from = trim((string) $this->checklist_title);
        $to = trim((string) $this->position_title);
        if ($from !== '' && mb_strtolower($from) !== mb_strtolower($to)) {
            return ['type' => 'title', 'from' => $from, 'to' => $to !== '' ? $to : null];
        }

        return null;
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

    /** Foto profil dari Sistem Absensi (salinan lokal, `EmployeePhotoSync`); null = tampilkan inisial. */
    public function photoUrl(): ?string
    {
        return $this->photo_cached_version ? route('chat.employee-photo', ['employee' => $this->id, 'v' => $this->photo_cached_version]) : null;
    }
}
