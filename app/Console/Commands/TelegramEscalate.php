<?php

namespace App\Console\Commands;

use App\Models\GuestRequest;
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
                $card = TelegramCard::where('guest_request_id', $r->id)->where('origin', false)->first();
                if ($level <= $r->escalated || ! $card) {
                    return;
                }
                $text = ($level === 2 ? '🔴 ' : '⏰ ').'<b>'.e(__('Kamar').' '.$r->room?->number).'</b> · '.e($r->label())
                    .' '.e(__('belum diambil :n menit. Ada yang bisa membantu?', ['n' => $minutes]));
                $api->send($card->chat_id, $text, null, $card->message_id);
                GuestRequest::whereKey($r->id)->update(['escalated' => $level]);
            });

        return self::SUCCESS;
    }
}
