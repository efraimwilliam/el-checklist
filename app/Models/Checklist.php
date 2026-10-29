<?php

namespace App\Models;

use App\Enums\ChecklistStatus;
use App\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu checklist = satu staff, pada satu jabatan, di satu tanggal. */
class Checklist extends Model
{
    protected $fillable = [
        'employee_id', 'department_id', 'position_id', 'position_name', 'date', 'status', 'note', 'checked_by', 'filled_by',
        'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'status' => ChecklistStatus::class, 'reviewed_at' => 'datetime'];
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

    /** Parameter halaman isi (/isi) untuk membuka checklist ini lagi. */
    public function fillParams(): array
    {
        return [
            'department' => $this->department_id, 'position' => $this->position_id, 'employee' => $this->employee_id,
            'date' => $this->date->toDateString(), 'checker' => $this->checked_by, 'mulai' => 1,
        ];
    }

    public function isReviewed(): bool
    {
        return $this->status === ChecklistStatus::Reviewed;
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
