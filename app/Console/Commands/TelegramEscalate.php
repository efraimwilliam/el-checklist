<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\GuestRequest;
use App\Models\RoomIssue;
use App\Models\User;
use App\Telegram\Cards;
use App\Models\TelegramCard;
use App\Telegram\TelegramApi;
use Illuminate\Console\Command;

/** Mengingatkan di grup Telegram bila permintaan tamu belum diambil: balasan di kartunya pada menit 15 dan 30 (tiap tingkat sekali). */
class TelegramEscalate extends Command
{
    protected $signature = 'telegram:eskalasi';

    protected $description = 'Ingatkan di Telegram permintaan tamu yang belum diambil (15 dan 30 menit)';

    /** Menit tunggu => tingkat. Yang lebih tua dari 3 jam diabaikan supaya antrean lama tidak membanjiri grup. */
    private const LEVELS = [30 => 2, 15 => 1];

    /** Menit tunggu => tingkat peringatan ke HOD. */
    private const HOD_LEVELS = [120 => 3, 60 => 2, 30 => 1];

    public function handle(TelegramApi $api): int
    {
        if (! $api->enabled()) {
            return self::SUCCESS;
        }

        GuestRequest::with('room')->where('status', GuestRequest::STATUS_NEW)->where('escalated', '<', 2)
            ->whereBetween('created_at', [now()->subHours(3), now()->subMinutes(15)])->get()
            ->each(function (GuestRequest $r) use ($api) {
                $minutes = (int) $r->created_at->diffInMinutes(now());
                $level = collect(self::LEVELS)->first(fn ($lv, $min) => $minutes >= $min, 0);
                $card = TelegramCard::where('guest_request_id', $r->id)->where('origin', false)->where('chat_id', config('services.telegram.chat_id'))->first();
                if ($level <= $r->escalated || ! $card) {
                    return;
                }
                $text = ($level === 2 ? '🔴 ' : '⏰ ').'<b>'.e(__('Kamar').' '.$r->room?->number).'</b> · '.e($r->label())
                    .' '.e(__('belum diambil :n menit. Ada yang bisa membantu?', ['n' => $minutes]));
                $api->send($card->chat_id, $text, null, $card->message_id);
                GuestRequest::whereKey($r->id)->update(['escalated' => $level]);
            });

        $this->alertHods($api);
        $this->remindClaimed($api);

        return self::SUCCESS;
    }

    /** Jam tenang (WITA): pengingat "sudah selesai?" tidak dikirim, supaya yang sudah pulang tidak diganggu; terkirim pagi harinya. */
    private function quietHours(): bool
    {
        return now()->hour >= 22 || now()->hour < 6;
    }

    /**
     * Yang sudah diambil tapi belum selesai (Pending tidak diingatkan): pesan pribadi ke pengambil, lalu ke HOD.
     * Permintaan tamu 1 jam / 3 jam, kendala 4 jam / 8 jam sejak diambil.
     */
    private function remindClaimed(TelegramApi $api): void
    {
        if ($this->quietHours()) {
            return;
        }
        $sets = [
            [GuestRequest::with('room', 'claimer')->where('status', GuestRequest::STATUS_CLAIMED), 60, 180, 'rq'],
            [RoomIssue::with('room', 'category', 'claimer')->where('status', RoomIssue::STATUS_WORKING), 240, 480, 'is'],
        ];
        foreach ($sets as [$query, $first, $second, $kind]) {
            $query->whereNotNull('claimed_by')->where('claimed_reminders', '<', 2)->whereBetween('claimed_at', [now()->subDays(3), now()->subMinutes($first)])->get()
                ->each(function (GuestRequest|RoomIssue $item) use ($api, $first, $second, $kind) {
                    $minutes = (int) $item->claimed_at->diffInMinutes(now());
                    $level = $minutes >= $second ? 2 : 1;
                    if ($level <= $item->claimed_reminders) {
                        return;
                    }
                    [$html, $keyboard] = $item instanceof GuestRequest ? Cards::request($item) : Cards::issue($item);
                    $hours = max(1, (int) round($minutes / 60));
                    $targets = $level === 1
                        ? collect([$item->claimer])->filter()
                        : User::where('role', Role::Hod)->whereHas('departments', fn ($q) => $q->whereKey($item->assigned_department_id))->get();
                    $text = $level === 1
                        ? '⏳ '.e(__('Sudah :h jam sejak Anda ambil. Sudah selesai?', ['h' => $hours]))."\n".$html
                        : '⏳ '.e(__(':name mengambil ini :h jam lalu dan belum selesai.', ['name' => $item->claimer?->name(), 'h' => $hours]))."\n".$html;
                    foreach ($targets->filter(fn (User $u) => $u->telegram_id) as $user) {
                        $sent = $api->send($user->telegram_id, $text, $keyboard ?: null);
                        isset($sent['message_id']) && TelegramCard::firstOrCreate(['chat_id' => $user->telegram_id, 'message_id' => $sent['message_id']], [$kind === 'rq' ? 'guest_request_id' : 'room_issue_id' => $item->id]);
                    }
                    $item::whereKey($item->id)->update(['claimed_reminders' => $level]);
                });
        }
    }

    /** Peringatan pribadi ke HOD departemen tujuan: 30, 60, dan 120 menit sesudah dicatat bila masih belum diambil (permintaan dan kendala). */
    private function alertHods(TelegramApi $api): void
    {
        $unclaimed = [
            GuestRequest::with('room')->where('status', GuestRequest::STATUS_NEW)->whereNull('claimed_by'),
            RoomIssue::with(['room', 'category'])->where('status', RoomIssue::STATUS_NEW)->whereNull('claimed_by'),
        ];
        foreach ($unclaimed as $query) {
            $query->where('hod_alerts', '<', 3)->whereBetween('created_at', [now()->subDay(), now()->subMinutes(30)])->get()
                ->each(function (GuestRequest|RoomIssue $item) use ($api) {
                    $minutes = (int) $item->created_at->diffInMinutes(now());
                    $level = collect(self::HOD_LEVELS)->first(fn ($lv, $min) => $minutes >= $min, 0);
                    $hods = $item->assigned_department_id ? User::where('role', Role::Hod)->whereNotNull('telegram_id')
                        ->whereHas('departments', fn ($q) => $q->whereKey($item->assigned_department_id))->get() : collect();
                    if ($level <= $item->hod_alerts || $hods->isEmpty()) {
                        return;
                    }
                    [$html, $keyboard] = $item instanceof GuestRequest ? Cards::request($item) : Cards::issue($item);
                    $kind = $item instanceof GuestRequest ? 'rq' : 'is';
                    $keyboard[] = [['text' => __('Tugaskan ke…'), 'callback_data' => 'as:'.$kind.':'.$item->id]];
                    $text = '🔴 <b>'.e(__('Peringatan :n/3', ['n' => $level])).'</b> · '.e(__('belum diambil :m menit', ['m' => $minutes]))."\n".$html;
                    foreach ($hods as $hod) {
                        $sent = $api->send($hod->telegram_id, $text, $keyboard);
                        isset($sent['message_id']) && TelegramCard::firstOrCreate(['chat_id' => $hod->telegram_id, 'message_id' => $sent['message_id']], [$kind === 'rq' ? 'guest_request_id' : 'room_issue_id' => $item->id]);
                    }
                    $item::whereKey($item->id)->update(['hod_alerts' => $level]);
                });
        }
    }
}
