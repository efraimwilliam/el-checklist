<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Laporan kronologi kejadian dengan tamu (Chronology Report): data tamu dan menginap, poin-poin bernomor berurutan, lalu nama penulis.
 * Draft bisa diubah penulisnya; setelah Final terkunci.
 */
class ChronologyReport extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINAL = 'final';

    protected $fillable = ['author_id', 'department_id', 'nationality', 'check_in', 'check_out', 'booking_source', 'room_issue_id', 'status', 'finalized_at'];

    protected function casts(): array
    {
        return ['check_in' => 'date', 'check_out' => 'date', 'finalized_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function roomIssue(): BelongsTo
    {
        return $this->belongsTo(RoomIssue::class);
    }

    public function guests(): HasMany
    {
        return $this->hasMany(ChronologyReportGuest::class)->orderBy('sort')->orderBy('id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ChronologyReportEntry::class)->orderBy('sort')->orderBy('id');
    }

    public function isFinal(): bool
    {
        return $this->status === self::STATUS_FINAL;
    }

    /** Admin / HR / GM melihat semua; HOD laporan departemennya; lainnya hanya yang ditulisnya sendiri. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role->seesAllDepartments()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('author_id', $user->id);
            if ($user->role === \App\Enums\Role::Hod) {
                $q->orWhereIn('department_id', $user->departments->pluck('id')->all());
            }
        });
    }
}
