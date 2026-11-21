<?php

namespace App\Telegram;

use App\Models\GuestRequest;
use App\Models\LostItem;
use App\Models\RoomIssue;

/** Teks (HTML) dan tombol kartu permintaan / kendala di Telegram. Tombol = callback `rq|is : aksi : id`. */
final class Cards
{
    /** @return array{0: string, 1: list<list<array{text: string, callback_data: string}>>} */
    public static function request(GuestRequest $r): array
    {
        $r->loadMissing(['room', 'claimer.employee', 'completer.employee']);
        $status = match ($r->status) {
            GuestRequest::STATUS_DONE => '✅ '.__('Selesai').' · '.($r->completer ?? $r->claimer)?->name(),
            GuestRequest::STATUS_CLAIMED => '✋ '.__('Diambil').' · '.$r->claimer?->name(),
            default => '🕓 '.__('Baru'),
        };
        $html = '🛎 <b>'.e(__('Kamar').' '.$r->room?->number).'</b> · '.e($r->label())
            .($r->priority === 'mendesak' ? ' · <b>'.e(__('Mendesak')).'</b>' : '')
            .($r->note && $r->source !== 'chat' ? "\n".e($r->note) : '')
            ."\n".e($status);

        $keys = match ($r->status) {
            GuestRequest::STATUS_NEW => [['text' => __('Saya ambil'), 'callback_data' => 'rq:claim:'.$r->id], ['text' => __('Selesai'), 'callback_data' => 'rq:done:'.$r->id], ['text' => '🗑', 'callback_data' => 'rq:del:'.$r->id]],
            GuestRequest::STATUS_CLAIMED => [['text' => __('Selesai'), 'callback_data' => 'rq:done:'.$r->id]],
            default => [],
        };

        return [$html, $keys ? [$keys] : []];
    }

    /** Barang tertinggal: tanpa tombol (status diubah dengan membalas kartu: "diambil nama", "dikirim resi", "dilepas"). @return array{0: string, 1: array} */
    public static function lost(LostItem $l): array
    {
        $l->loadMissing(['room', 'resolver.employee']);
        $html = '🧳 <b>'.e($l->placeTitle()).'</b> · '.e($l->description)
            ."\n".($l->isOpen()
                ? '🕓 '.e(__('Disimpan')).($l->stored_at ? ' · '.e($l->stored_at) : '')."\n".'<i>'.e(__('Balas kartu ini: "diambil nama penerima", "dikirim nomor resi", atau "dilepas".')).'</i>'
                : '✅ '.e(LostItem::statusLabels()[$l->status]).($l->resolution_note ? ' · '.e($l->resolution_note) : '').($l->resolver ? ' · '.e($l->resolver->name()) : ''));

        return [$html, []];
    }

    /** @return array{0: string, 1: list<list<array{text: string, callback_data: string}>>} */
    public static function issue(RoomIssue $i): array
    {
        $i->loadMissing(['room', 'category', 'claimer.employee']);
        $status = match ($i->status) {
            RoomIssue::STATUS_DONE => '✅ Done · '.($i->completer ?? $i->claimer)?->name(),
            RoomIssue::STATUS_NEW => '🕓 '.__('Baru'),
            default => '✋ '.RoomIssue::statusLabels()[$i->status].' · '.$i->claimer?->name(),
        };
        $html = '🛠 <b>'.e($i->placeTitle()).'</b>'.($i->category ? ' · '.e($i->category->name) : '')
            .($i->priority === 'mendesak' ? ' · <b>'.e(__('Mendesak')).'</b>' : '')
            ."\n".e(mb_substr((string) $i->description, 0, 300))
            ."\n".e($status);

        $keys = match ($i->status) {
            RoomIssue::STATUS_NEW => [['text' => __('Saya ambil'), 'callback_data' => 'is:claim:'.$i->id], ['text' => __('Selesai'), 'callback_data' => 'is:done:'.$i->id]],
            RoomIssue::STATUS_DONE => [],
            default => [['text' => __('Selesai'), 'callback_data' => 'is:done:'.$i->id]],
        };

        return [$html, $keys ? [$keys] : []];
    }
}
