<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permintaan tamu yang diteruskan Front Office ke departemen (biasanya Housekeeping): air mineral, handuk, laundry, MUR, dst.
 * Alurnya sependek chat: dicatat -> "Saya ambil" (pengganti "Copy") -> "Selesai" (pengganti "Done"). Waktu tiap tahap tersimpan.
 */
class GuestRequest extends Model
{
    public const STATUS_NEW = 'baru';

    public const STATUS_CLAIMED = 'diambil';

    public const STATUS_DONE = 'selesai';

    public const PRIORITIES = ['biasa', 'mendesak'];

    /** Jenis permintaan ada di tabel guest_request_kinds (App\Requests\RequestKinds), diatur di Kamus ketik bebas. */
    public static function kinds(): \App\Requests\RequestKinds
    {
        return app(\App\Requests\RequestKinds::class);
    }

    protected $fillable = ['room_id', 'kind', 'item_name', 'quantity', 'note', 'priority', 'source', 'requested_by', 'assigned_department_id', 'status', 'claimed_by', 'claimed_at', 'completed_by', 'completed_at'];

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /** @return array<string, string> */
    public static function kindLabels(): array
    {
        return self::kinds()->labels();
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [self::STATUS_NEW => __('Baru'), self::STATUS_CLAIMED => __('Diambil'), self::STATUS_DONE => 'Done'];
    }

    /** Departemen bawaan semua jenis permintaan: Housekeeping (dicocokkan lewat nama). */
    public static function defaultDepartmentId(): ?int
    {
        return Department::where('is_active', true)->where('name', 'like', 'Housekeeping%')->orderBy('id')->value('id');
    }

    public function kindLabel(): string
    {
        return self::kindLabels()[$this->kind] ?? $this->kind;
    }

    /** "2× Air mineral" bila jenisnya memakai jumlah, selain itu nama jenisnya saja. */
    public function label(): string
    {
        // "Lainnya" dengan nama barang (dari ketik bebas): "Lainnya: lan cable", jumlah hanya bila lebih dari satu.
        if ($this->kind === 'other' && filled($this->item_name)) {
            return ($this->quantity > 1 ? $this->quantity.'× ' : '').$this->kindLabel().': '.$this->item_name;
        }

        return self::kinds()->hasQuantity((string) $this->kind) && $this->quantity > 0 ? $this->quantity.'× '.$this->kindLabel() : $this->kindLabel();
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'assigned_department_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** Menit dari dicatat sampai diambil (null bila belum). */
    public function minutesToClaim(): ?int
    {
        return $this->claimed_at ? (int) round($this->created_at->diffInSeconds($this->claimed_at) / 60) : null;
    }

    /** Menit dari dicatat sampai selesai (null bila belum). */
    public function minutesToDone(): ?int
    {
        return $this->completed_at ? (int) round($this->created_at->diffInSeconds($this->completed_at) / 60) : null;
    }

    /** Tujuannya departemen pengguna (HOD: departemen yang dipegang). */
    public function isForUser(User $user): bool
    {
        return $this->assigned_department_id !== null && in_array($this->assigned_department_id, $user->issueDepartmentIds(), true);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereIn('assigned_department_id', $user->issueDepartmentIds() ?: [0]);
    }

    /** Admin / HR / GM melihat semua; lainnya yang dicatatnya sendiri atau yang diteruskan ke departemennya. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role->seesAllDepartments()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->where('requested_by', $user->id)->orWhere(fn (Builder $f) => $f->forUser($user)));
    }
}
