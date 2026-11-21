<?php

namespace App\Telegram;

use App\Checklists\ChecklistService;
use App\Checklists\ParameterRules;
use App\Enums\ItemStatus;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Checklist dari bot (chat pribadi): /checklist membuka checklist milik sendiri hari ini, satu tugas per pesan dengan tombol
 * Done / Ongoing / Pending dan navigasi. Hanya tugas berparameter status saja (angka, meter, pilihan, foto -> aplikasi).
 * Aturan sama dengan web: `ChecklistPolicy::update` dan `ChecklistService::saveItem`. Tombol = `cl:o|n|s|r|x : ...`.
 */
class ChecklistFlow
{
    public const REASON = 'telegram.checklist.reason.';

    public function __construct(private readonly TelegramApi $api, private readonly ChecklistService $service) {}

    /** `/checklist`: checklist milik sendiri hari ini. */
    public function open(User $user, int $chat): void
    {
        if (! Gate::forUser($user)->allows('fill-checklists') || ! $user->employee_id) {
            $this->api->send($chat, e(__('Akun Anda tidak punya checklist untuk diisi.')));

            return;
        }
        $mine = Checklist::where('employee_id', $user->employee_id)->whereDate('date', today())->orderBy('id')->get();
        if ($mine->isEmpty()) {
            $this->api->send($chat, e(__('Belum ada checklist untuk Anda hari ini. Minta HOD atau HR membuatkannya.')));
        } elseif ($mine->count() > 1) {
            $this->api->send($chat, e(__('Pilih checklist:')), $mine->map(fn (Checklist $c) => [['text' => $c->position_name, 'callback_data' => 'cl:o:'.$c->id]])->all());
        } else {
            $this->show($mine->first(), null, $chat);
        }
    }

    /** Tombol kartu: `cl:o:CHK`, `cl:n:CHK:IDX`, `cl:s:CHK:ITEM:status`, `cl:r:CHK`. */
    public function callback(User $user, string $action, string $key, int $chat, int $messageId, string $callbackId): void
    {
        $parts = explode(':', $key);
        $checklist = Checklist::find((int) $parts[0]);
        if (! $checklist || $checklist->employee_id !== $user->employee_id || ! Gate::forUser($user)->allows('update', $checklist)) {
            $this->api->answer($callbackId, __('Checklist ini tidak bisa Anda ubah (sudah direview atau bukan milik Anda).'), true);

            return;
        }
        if ($action === 'o') {
            $this->show($checklist, null, $chat, $messageId);
        } elseif ($action === 'n') {
            $this->show($checklist, (int) ($parts[1] ?? 0), $chat, $messageId);
        } elseif ($action === 'r') {
            $this->summary($checklist, $chat, $messageId);
        } elseif ($action === 's') {
            $item = $checklist->items()->with('parameters')->find((int) ($parts[1] ?? 0));
            $status = ItemStatus::tryFrom((string) ($parts[2] ?? ''));
            if (! $item || ! $status || ! $this->simple($item)) {
                $this->api->answer($callbackId, __('Tugas ini diisi lewat aplikasi.'), true);

                return;
            }
            $this->service->saveItem($item, $status->value, $item->note, $user);
            $items = $this->items($checklist->fresh());
            $index = $items->search(fn (ChecklistItem $i) => $i->id === $item->id);
            if ($status === ItemStatus::Pending) {
                Cache::put(self::REASON.$user->id, ['item' => $item->id, 'chat' => $chat, 'message' => $messageId], now()->addMinutes(30));
            } else {
                Cache::forget(self::REASON.$user->id);
                $status === ItemStatus::Done && $index !== false && $index + 1 < $items->count() && $index++; // setelah Done: tugas berikutnya
            }
            $this->show($checklist->fresh(), $index === false ? 0 : $index, $chat, $messageId);
        }
        $this->api->answer($callbackId);
    }

    public function waitingReason(User $user): bool
    {
        return Cache::has(self::REASON.$user->id);
    }

    /** Teks sesudah Pending = alasan, disimpan sebagai catatan tugas. */
    public function saveReason(User $user, string $text): void
    {
        $state = Cache::pull(self::REASON.$user->id);
        $item = $state ? ChecklistItem::with(['checklist', 'parameters'])->find($state['item']) : null;
        if (! $item || ! Gate::forUser($user)->allows('update', $item->checklist)) {
            return;
        }
        $this->service->saveItem($item, ItemStatus::Pending->value, mb_substr($text, 0, 1000), $user);
        $items = $this->items($item->checklist);
        $this->show($item->checklist->fresh(), (int) $items->search(fn (ChecklistItem $i) => $i->id === $item->id), (int) $state['chat'], (int) $state['message']);
    }

    private function show(Checklist $checklist, ?int $index, int $chat, ?int $messageId = null): void
    {
        $items = $this->items($checklist);
        if ($items->isEmpty()) {
            $this->api->send($chat, e(__('Checklist ini belum punya tugas.')));

            return;
        }
        $index ??= (int) $items->search(fn (ChecklistItem $i) => $i->status === null) ?: 0; // tugas pertama yang belum diisi
        $index = max(0, min($index, $items->count() - 1));
        $item = $items[$index];
        $done = $items->whereNotNull('status')->count();

        $html = '📋 <b>'.e($checklist->position_name).'</b> · '.e($checklist->date->translatedFormat('d M')).' · '.$done.'/'.$items->count()
            ."\n\n".'<b>'.($index + 1).'/'.$items->count().'</b>'.($item->section ? ' · '.e($item->section) : '')
            ."\n".'<b>'.e($item->title).'</b>'
            .($item->standard ? "\n".'<i>'.e(mb_substr($item->standard, 0, 400)).'</i>' : '')
            ."\n\n".($item->status ? ['done' => '✅', 'ongoing' => '🔄', 'pending' => '⏸'][$item->status->value].' '.e($item->status->label()) : '▫️ '.e(__('Belum diisi')))
            .($item->note ? "\n".e(__('Catatan')).': '.e($item->note) : '');
        $pendingNote = $item->status === ItemStatus::Pending && blank($item->note);
        $pendingNote && $html .= "\n".'<i>'.e(__('Ketik alasan Pending sebagai pesan berikutnya.')).'</i>';

        $keys = [];
        if ($this->simple($item)) {
            $row = [];
            foreach ([ItemStatus::Done, ItemStatus::Ongoing, ItemStatus::Pending] as $status) {
                $row[] = ['text' => ($item->status === $status ? '• ' : '').$status->label(), 'callback_data' => 'cl:s:'.$checklist->id.':'.$item->id.':'.$status->value];
            }
            $keys[] = $row;
        } else {
            $html .= "\n\n".'<i>'.e(__('Tugas ini punya isian khusus (angka, pilihan, atau foto). Isi lewat aplikasi.')).'</i>';
        }
        $keys[] = [
            ['text' => '‹', 'callback_data' => 'cl:n:'.$checklist->id.':'.max(0, $index - 1)],
            ['text' => '📋 '.__('Ringkasan'), 'callback_data' => 'cl:r:'.$checklist->id],
            ['text' => '›', 'callback_data' => 'cl:n:'.$checklist->id.':'.min($items->count() - 1, $index + 1)],
        ];

        $messageId ? $this->api->edit($chat, $messageId, $html, $keys) : $this->api->send($chat, $html, $keys);
    }

    private function summary(Checklist $checklist, int $chat, int $messageId): void
    {
        $progress = $this->service->progress($checklist->fresh());
        $html = '📋 <b>'.e($checklist->position_name).'</b> · '.e($checklist->date->translatedFormat('d M'))
            ."\n".'✅ '.$progress['done'].'  🔄 '.$progress['ongoing'].'  ⏸ '.$progress['pending'].'  ▫️ '.($progress['total'] - $progress['filled'])
            ."\n".e(__('Skor sementara')).': <b>'.($progress['score'] ?? 0).'%</b>'
            ."\n".($progress['complete'] ? '✅ '.e(__('Lengkap.')) : '⚠️ '.e(__('Belum lengkap')).($progress['missing_reasons'] ? ' · '.e(__(':n Pending tanpa alasan', ['n' => $progress['missing_reasons']])) : '').($progress['missing_photos'] ? ' · '.e(__(':n tugas kurang foto (isi di aplikasi)', ['n' => $progress['missing_photos']])) : ''));
        $this->api->edit($chat, $messageId, $html, [[['text' => '‹ '.__('Kembali ke tugas'), 'callback_data' => 'cl:n:'.$checklist->id.':0']]]);
    }

    /** @return Collection<int, ChecklistItem> */
    private function items(Checklist $checklist): Collection
    {
        return $checklist->items()->with('parameters')->orderBy('sort')->orderBy('id')->get()->values();
    }

    /** Hanya satu parameter bertipe status: bisa diisi dengan tombol. */
    private function simple(ChecklistItem $item): bool
    {
        return $item->parameters->count() === 1 && $item->parameters->first()->type === ParameterRules::STATUS;
    }
}
