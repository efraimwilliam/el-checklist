<?php

namespace App\Telegram;

use App\Models\GuestRequest;
use App\Models\LostItem;
use App\Models\RoomIssue;
use App\Models\User;

/** Teks (HTML) perintah bot: /daftar (yang belum selesai + siapa yang mengambil), /respon (hari ini), /anggota (siapa sudah tertaut). */
final class Reports
{
    /** @param ?User $only null = semua (grup); pengguna = hanya yang menjadi tanggung jawabnya (chat pribadi). */
    public static function open(?User $only = null): string
    {
        $requests = GuestRequest::with(['room', 'claimer.employee'])->where('status', '!=', GuestRequest::STATUS_DONE)->when($only, fn ($q) => $q->forUser($only))->orderBy('id')->limit(40)->get();
        $issues = RoomIssue::with(['room', 'category', 'claimer.employee'])->where('status', '!=', RoomIssue::STATUS_DONE)->when($only, fn ($q) => $q->forUser($only))->orderBy('id')->limit(40)->get();

        $lines = $requests->map(fn (GuestRequest $r) => '🛎 '.e(__('Kamar').' '.$r->room?->number).' · '.e($r->label()).' — '
            .($r->claimer ? '✋ '.e($r->claimer->name()) : '🕓 '.e(__('belum diambil :n mnt', ['n' => (int) $r->created_at->diffInMinutes(now())]))))
            ->merge($issues->map(fn (RoomIssue $i) => '🛠 '.e($i->placeTitle()).' · '.e((string) $i->category?->name).' — '
                .($i->claimer ? '✋ '.e($i->claimer->name()).' ('.e(RoomIssue::statusLabels()[$i->status]).')' : '🕓 '.e(__('belum diambil')))));

        return $lines->isEmpty() ? '✅ '.e(__('Tidak ada permintaan atau kendala yang terbuka.'))
            : '<b>'.e(__('Belum selesai')).' ('.$lines->count().')</b>'."\n".$lines->join("\n");
    }

    public static function lost(): string
    {
        $items = LostItem::with('room')->where('status', LostItem::STATUS_STORED)->orderBy('id')->limit(40)->get();

        return $items->isEmpty() ? '✅ '.e(__('Tidak ada barang tertinggal yang sedang disimpan.'))
            : '<b>'.e(__('Barang tertinggal disimpan')).' ('.$items->count().')</b>'."\n"
                .$items->map(fn (LostItem $l) => '🧳 '.e($l->placeTitle()).' · '.e($l->description).($l->stored_at ? ' · '.e($l->stored_at) : '').' · '.(int) $l->found_at->diffInDays(now()).' '.e(__('hari')))->join("\n");
    }

    public static function response(): string
    {
        $today = GuestRequest::with('claimer.employee')->whereDate('created_at', today())->get();
        if ($today->isEmpty()) {
            return e(__('Belum ada permintaan hari ini.'));
        }
        $avg = fn ($values) => $values->isEmpty() ? '—' : round($values->avg()).' '.__('mnt');
        $out = '<b>'.e(__('Permintaan hari ini')).'</b>: '.$today->count().' · '.e(__('selesai')).' '.$today->filter->isDone()->count()
            ."\n".e(__('Rata-rata sampai diambil')).': '.e($avg($today->map->minutesToClaim()->filter(fn ($m) => $m !== null)))
            ."\n".e(__('Rata-rata sampai selesai')).': '.e($avg($today->map->minutesToDone()->filter(fn ($m) => $m !== null)));
        $per = $today->whereNotNull('claimed_by')->groupBy('claimed_by')->map(fn ($rows) => $rows->first()->claimer->name().' '.$rows->count())->sortDesc();

        return $out.($per->isEmpty() ? '' : "\n\n".'<b>'.e(__('Yang mengambil')).'</b>'."\n".e($per->join("\n")));
    }

    public static function members(): string
    {
        $users = User::with('employee')->get()->filter->canUseApp();
        [$linked, $unlinked] = [$users->whereNotNull('telegram_id'), $users->whereNull('telegram_id')];

        return '<b>'.e(__('Sudah tertaut')).' ('.$linked->count().')</b>'."\n".e($linked->map->name()->sort()->join(', ') ?: '—')
            ."\n\n".'<b>'.e(__('Belum tertaut')).' ('.$unlinked->count().')</b>'."\n".e($unlinked->map->name()->sort()->join(', ') ?: '—');
    }
}
