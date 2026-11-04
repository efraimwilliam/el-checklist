<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kendala di satu kamar: dicatat (biasanya oleh Front Office), ditugaskan ke satu departemen, lalu departemen itu
 * mencatat pekerjaannya sampai selesai. Dasar Peta kamar di Laporan.
 */
class RoomIssue extends Model
{
    public const STATUS_NEW = 'baru';

    public const STATUS_WORKING = 'dikerjakan';

    public const STATUS_PENDING = 'tertunda';

    public const STATUS_DONE = 'selesai';

    public const STATUSES = [self::STATUS_NEW, self::STATUS_WORKING, self::STATUS_PENDING, self::STATUS_DONE];

    public const SOURCES = ['tamu', 'staff', 'inspeksi'];

    /** Kendala dengan kategori yang sama di kamar yang sama dalam jangka ini dianggap berulang ("berulang ke-N"). */
    public const RECURRENCE_DAYS = 30;

    public const PRIORITIES = ['biasa', 'mendesak'];

    protected $fillable = ['room_id', 'category_id', 'source', 'description', 'priority', 'recurrence', 'reported_by', 'assigned_department_id', 'claimed_by', 'claimed_at', 'status', 'completed_at', 'completed_by'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'claimed_at' => 'datetime'];
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        // Sama dengan istilah di checklist: Ongoing = sedang dikerjakan, Pending = tertunda (wajib beralasan), Done = selesai.
        return [self::STATUS_NEW => __('Baru'), self::STATUS_WORKING => 'Ongoing', self::STATUS_PENDING => 'Pending', self::STATUS_DONE => 'Done'];
    }

    /** @return array<string, string> */
    public static function sourceLabels(): array
    {
        return ['tamu' => __('Laporan tamu'), 'staff' => __('Ditemukan staff'), 'inspeksi' => __('Inspeksi')];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(IssueCategory::class, 'category_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** Pegawai yang mengambil kendala ini (satu orang per kendala). */
    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'assigned_department_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(RoomIssueUpdate::class)->orderBy('created_at')->orderBy('id');
    }

    /** Nomor berulang untuk kendala baru di kamar dan kategori ini: 1 + kendala serupa dalam RECURRENCE_DAYS terakhir. */
    public static function nextRecurrence(int $roomId, ?int $categoryId): int
    {
        if ($categoryId === null) {
            return 1;
        }

        return min(255, static::where('room_id', $roomId)->where('category_id', $categoryId)->where('created_at', '>=', now()->subDays(self::RECURRENCE_DAYS))->count() + 1);
    }

    /** Kendala serupa (kamar dan kategori sama) yang belum selesai, bila ada. */
    public static function openDuplicate(int $roomId, ?int $categoryId): ?self
    {
        if ($categoryId === null) {
            return null;
        }

        return static::where('room_id', $roomId)->where('category_id', $categoryId)->where('status', '!=', self::STATUS_DONE)->latest('id')->first();
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    /** Admin / HR / GM melihat semua; lainnya hanya yang dilaporkan sendiri atau ditugaskan ke departemennya. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role->seesAllDepartments()) {
            return $query;
        }

        // Pelapor, departemen penanggung jawab, atau yang pernah menambahkan laporan / catatan pada kendala itu.
        return $query->where(fn (Builder $q) => $q->where('reported_by', $user->id)
            ->orWhereIn('assigned_department_id', $user->issueDepartmentIds())
            ->orWhereHas('updates', fn (Builder $u) => $u->where('user_id', $user->id)));
    }
}
