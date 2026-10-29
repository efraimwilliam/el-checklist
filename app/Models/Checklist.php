<?php

namespace App\Models;

use App\Enums\ChecklistStatus;
use App\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Checklist extends Model
{
    protected $fillable = [
        'employee_id', 'department_id', 'position_id', 'position_name', 'date', 'status', 'note',
        'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => ChecklistStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('sort')->orderBy('id');
    }

    /** Persentase tugas selesai (0–100), atau null bila tanpa tugas. */
    public function score(): ?int
    {
        $total = $this->items->count();

        return $total ? (int) round($this->items->where('status', ItemStatus::Done)->count() / $total * 100) : null;
    }

    /** @return array<string, int> jumlah per status ItemStatus, plus 'empty' */
    public function counts(): array
    {
        $counts = ['empty' => $this->items->whereNull('status')->count()];
        foreach (ItemStatus::cases() as $status) {
            $counts[$status->value] = $this->items->where('status', $status)->count();
        }

        return $counts;
    }
}
