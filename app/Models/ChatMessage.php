<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ChatMessage extends Model
{
    /** Tautan di isi pesan (http / https), tanpa tanda baca penutup. */
    public const URL_PATTERN = '~https?://[^\s<>"\']+[^\s<>"\'.,;:!?)\]]~iu';

    protected $fillable = ['chat_thread_id', 'user_id', 'type', 'body', 'mentions', 'photo_path', 'file_path', 'file_name', 'file_size', 'file_mime', 'reply_to_id', 'room_issue_id', 'checklist_id', 'chronology_report_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime', 'mentions' => 'array'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'chat_thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    /** Kendala yang dikirim ke obrolan lewat "Kirim ke obrolan". */
    public function sharedIssue(): BelongsTo
    {
        return $this->belongsTo(RoomIssue::class, 'room_issue_id');
    }

    /** Checklist yang dikirim ke obrolan (pengingat). */
    public function sharedChecklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class, 'checklist_id');
    }

    /** Chronology Report yang dikirim ke obrolan. */
    public function sharedReport(): BelongsTo
    {
        return $this->belongsTo(ChronologyReport::class, 'chronology_report_id');
    }

    public function isSystem(): bool
    {
        return $this->type === 'system';
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function mentionsUser(User $user): bool
    {
        return in_array($user->id, $this->mentions ?? [], true);
    }

    /**
     * Isi pesan siap tampil: di-escape, lalu "@Nama" orang yang disebut dibuat tebal (yang menyebut pembaca disorot).
     *
     * @param  \Illuminate\Support\Collection<int, User>  $people  pengguna berkunci id (peserta obrolan)
     */
    public function bodyHtml($people, User $viewer): string
    {
        $html = e((string) $this->body);
        $names = collect($this->mentions ?? [])->map(fn ($id) => $people->get($id))->filter()
            ->sortByDesc(fn (User $u) => mb_strlen($u->name()));
        foreach ($names as $person) {
            $class = $person->id === $viewer->id ? 'rounded bg-sky-100 px-0.5 font-semibold text-sky-800' : 'font-semibold text-sky-700';
            $html = preg_replace('/@'.preg_quote(e($person->name()), '/').'/iu', '<span class="'.$class.'" data-mention="'.$person->id.'">$0</span>', $html) ?? $html;
        }

        // Tautan bisa diklik (dibuka di tab baru, tanpa membawa alamat aplikasi). Teks sudah di-escape, jadi &amp; tetap aman di href.
        return preg_replace_callback('~https?://[^\s<>"\']+[^\s<>"\'.,;:!?)\]]~iu', function ($match) {
            $url = $match[0];

            return '<a href="'.$url.'" target="_blank" rel="noopener noreferrer nofollow" class="text-sky-700 underline break-all">'.$url.'</a>';
        }, $html) ?? $html;
    }

    public function fileExtension(): string
    {
        return mb_strtoupper(pathinfo((string) $this->file_name, PATHINFO_EXTENSION) ?: 'FILE');
    }

    public function fileSizeLabel(): string
    {
        $size = (int) $this->file_size;

        return match (true) {
            $size >= 1048576 => number_format($size / 1048576, 1, ',', '.').' MB',
            $size >= 1024 => number_format($size / 1024, 0, ',', '.').' KB',
            default => $size.' B',
        };
    }

    /** Ringkasan satu baris untuk daftar obrolan dan kutipan balasan. */
    public function snippet(int $limit = 80): string
    {
        if ($this->isDeleted()) {
            return __('Pesan ini dihapus');
        }
        $text = Str::limit(preg_replace('/\s+/', ' ', (string) $this->body), $limit);
        if ($this->photo_path) {
            return '📷 '.($text !== '' ? $text : __('Foto'));
        }
        if ($this->room_issue_id) {
            return '🛠 '.($text !== '' ? $text : __('Kendala').' · '.($this->sharedIssue?->placeTitle() ?? '#'.$this->room_issue_id));
        }
        if ($this->chronology_report_id) {
            return '📝 '.($text !== '' ? $text : 'Chronology Report · '.($this->sharedReport?->guests->first()?->name ?? '#'.$this->chronology_report_id));
        }
        if ($this->checklist_id) {
            return '📋 '.($text !== '' ? $text : __('Checklist').' · '.($this->sharedChecklist?->employee?->name ?? '#'.$this->checklist_id));
        }
        if ($this->file_path) {
            return '📄 '.($text !== '' ? $text : $this->file_name);
        }

        return $text;
    }
}
