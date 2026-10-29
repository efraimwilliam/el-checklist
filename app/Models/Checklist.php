<?php

namespace App\Models;

use App\Checklists\ChecklistSettings;
use App\Enums\ChecklistStatus;
use App\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu checklist = satu staff, pada satu jabatan, di satu tanggal. */
class Checklist extends Model
{
    protected $fillable = [
        'employee_id', 'department_id', 'position_id', 'position_name', 'date', 'status', 'note', 'checked_by', 'filled_by', 'assigned_at',
        'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'status' => ChecklistStatus::class, 'reviewed_at' => 'datetime', 'assigned_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** Akun yang terakhir menyimpan. */
    public function filler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filled_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('sort')->orderBy('id');
    }

    public function extraTasks(): HasMany
    {
        return $this->hasMany(ChecklistExtraTask::class)->orderBy('sort')->orderBy('id');
    }

    /** Terkunci: sudah direview GM / HR. */
    public function isReviewed(): bool
    {
        return $this->status === ChecklistStatus::Reviewed;
    }

    /** Sudah diverifikasi HOD, masih bisa diubah sampai GM / HR mereview. */
    public function isHodReviewed(): bool
    {
        return $this->status === ChecklistStatus::HodReviewed;
    }

    /** Jumlah tugas yang sudah diberi status. */
    public function answeredCount(): int
    {
        return $this->items->whereNotNull('status')->count();
    }

    /** Tugas Pending yang belum diberi alasan (bila alasan wajib). */
    public function missingReasons(): int
    {
        return config('checklist.pending_note_required')
            ? $this->items->where('status', ItemStatus::Pending)->filter(fn ($item) => blank($item->note))->count()
            : 0;
    }

    /** Tugas yang masih kurang fotonya menurut pengaturan Administrator (opsional / wajib untuk Pending / wajib untuk semua). */
    public function missingPhotos(): int
    {
        $requirement = app(ChecklistSettings::class)->photoRequirement();
        if ($requirement === 'optional') {
            return 0;
        }

        return $this->items->loadMissing('photos')
            ->filter(fn ($item) => $requirement === 'all' || $item->status === ItemStatus::Pending)
            ->filter(fn ($item) => $item->photos->isEmpty())
            ->count();
    }

    /** Lengkap: semua tugas berstatus, setiap Pending beralasan, dan foto sesuai pengaturan. Hanya yang lengkap yang bisa direview. */
    public function isComplete(): bool
    {
        return $this->items->isNotEmpty() && $this->answeredCount() === $this->items->count()
            && $this->missingReasons() === 0 && $this->missingPhotos() === 0;
    }

    /** Persentase tugas Done (0-100), atau null bila tanpa tugas. Tugas tambahan tidak dihitung. */
    public function score(): ?int
    {
        $total = $this->items->count();

        return $total ? (int) round($this->items->where('status', ItemStatus::Done)->count() / $total * 100) : null;
    }

    /** @return array{done: int, ongoing: int, pending: int} */
    public function counts(): array
    {
        return [
            'done' => $this->items->where('status', ItemStatus::Done)->count(),
            'ongoing' => $this->items->where('status', ItemStatus::Ongoing)->count(),
            'pending' => $this->items->where('status', ItemStatus::Pending)->count(),
        ];
    }
}
