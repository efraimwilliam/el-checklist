<?php

namespace App\Telegram;

use App\Models\GuestRequest;
use App\Models\RoomIssue;
use App\Models\User;

/** Teks (HTML) perintah bot: /daftar (yang belum selesai + siapa yang mengambil), /respon (hari ini), /anggota (siapa sudah tertaut). */
final class Reports
{
    public static function open(): string
    {
        $requests = GuestRequest::with(['room', 'claimer.employee'])->where('status', '!=', GuestRequest::STATUS_DONE)->orderBy('id')->limit(40)->get();
        $issues = RoomIssue::with(['room', 'category', 'claimer.employee'])->where('status', '!=', RoomIssue::STATUS_DONE)->orderBy('id')->limit(40)->get();

        $lines = $requests->map(fn (GuestRequest $r) => '🛎 '.e(__('Kamar').' '.$r->room?->number).' · '.e($r->label()).' — '
            .($r->claimer ? '✋ '.e($r->claimer->name()) : '🕓 '.e(__('belum diambil :n mnt', ['n' => (int) $r->created_at->diffInMinutes(now())]))))
            ->merge($issues->map(fn (RoomIssue $i) => '🛠 '.e($i->placeTitle()).' · '.e((string) $i->category?->name).' — '
                .($i->claimer ? '✋ '.e($i->claimer->name()).' ('.e(RoomIssue::statusLabels()[$i->status]).')' : '🕓 '.e(__('belum diambil')))));

        return $lines->isEmpty() ? '✅ '.e(__('Tidak ada permintaan atau kendala yang terbuka.'))
            : '<b>'.e(__('Belum selesai')).' ('.$lines->count().')</b>'."\n".$lines->join("\n");
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
