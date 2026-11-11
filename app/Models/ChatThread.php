<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Satu percakapan: chat pribadi (dua orang, `direct_key`), grup (judul + peserta), atau menempel pada satu kendala / satu checklist
 * (yang boleh melihat kendala / checklist itu boleh ikut).
 */
class ChatThread extends Model
{
    protected $fillable = ['subject', 'photo_path', 'direct_key', 'room_issue_id', 'checklist_id', 'created_by', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    /**
     * Tujuan "Kirim ke obrolan" (kendala / checklist): obrolan terbaru pengguna dan semua kontak aktif. Kosong bila obrolan dimatikan.
     *
     * @return array{shareThreads: \Illuminate\Support\Collection, sharePeople: \Illuminate\Support\Collection}
     */
    public static function shareTargets(User $me): array
    {
        if (! \Illuminate\Support\Facades\Gate::forUser($me)->allows('chat')) {
            return ['shareThreads' => collect(), 'sharePeople' => collect()];
        }

        return [
            'shareThreads' => self::whereHas('participants', fn ($q) => $q->where('user_id', $me->id))->whereNotNull('last_message_at')
                ->with(['participants.user.employee.department', 'issue.room', 'checklist.employee'])
                ->orderByDesc('last_message_at')->limit(30)->get(),
            'sharePeople' => User::with('employee.department')->where('is_active', true)->whereKeyNot($me->id)
                ->whereHas('employee', fn ($q) => $q->where('is_active', true))
                ->get()->sortBy(fn ($u) => mb_strtolower($u->name()))->values(),
        ];
    }

    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).'-'.max($a, $b);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany();
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(RoomIssue::class, 'room_issue_id');
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    public function isDirect(): bool
    {
        return $this->direct_key !== null;
    }

    public function isLinked(): bool
    {
        return $this->room_issue_id !== null || $this->checklist_id !== null;
    }

    /** Grup biasa (bukan chat pribadi, bukan obrolan kendala / checklist): peserta bisa menambah orang dan keluar. */
    public function isGroup(): bool
    {
        return ! $this->isDirect() && ! $this->isLinked();
    }

    /** Peserta selain yang melihat (chat pribadi: lawan bicara). */
    public function others(?User $viewer)
    {
        return $this->participants->pluck('user')->filter()->reject(fn (User $u) => $viewer && $u->id === $viewer->id)->values();
    }

    public function title(?User $viewer = null): string
    {
        if ($this->isDirect()) {
            $others = $this->others($viewer);

            return $others->count() === 1 ? $others->first()->name() : $others->map->name()->implode(' & ');
        }
        if ($this->subject) {
            return $this->subject;
        }
        if ($this->room_issue_id) {
            return __('Kendala').' · '.($this->issue?->placeTitle() ?? '#'.$this->room_issue_id);
        }
        if ($this->checklist_id) {
            return __('Checklist').' · '.($this->checklist?->employee?->name ?? '—').' · '.$this->checklist?->date?->format('d M Y');
        }

        return __('Obrolan');
    }

    /** Keterangan di bawah judul: jabatan / departemen lawan bicara, atau nama peserta. */
    public function subtitle(?User $viewer = null): string
    {
        if ($this->isDirect()) {
            $other = $this->others($viewer)->first();

            return $other ? collect([$other->employee?->position_title, $other->employee?->department?->name])->filter()->implode(' · ') : '';
        }

        return $this->participants->pluck('user')->filter()->map(fn (User $u) => $viewer && $u->id === $viewer->id ? __('Anda') : $u->name())->implode(', ');
    }

    public function initials(?User $viewer = null): string
    {
        $words = preg_split('/\s+/', trim($this->title($viewer))) ?: [];

        return mb_strtoupper(collect($words)->filter(fn ($w) => preg_match('/^\p{L}/u', $w))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) ?: '#';
    }

    public function isParticipant(User $user): bool
    {
        return $this->relationLoaded('participants')
            ? $this->participants->contains('user_id', $user->id)
            : $this->participants()->where('user_id', $user->id)->exists();
    }
}
