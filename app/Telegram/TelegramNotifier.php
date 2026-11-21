<?php

namespace App\Telegram;

use App\Models\GuestRequest;
use App\Models\RoomIssue;
use App\Models\TelegramCard;

/**
 * Kartu permintaan / kendala di grup Telegram (`services.telegram.chat_id`): satu kartu saat dicatat dari jalur mana pun (web, aplikasi,
 * obrolan, bot), lalu diperbarui di tempat setiap kali statusnya berubah (`refresh`, dipanggil tempat yang mengubah status).
 */
class TelegramNotifier
{
    public function __construct(private readonly TelegramApi $api) {}

    public function post(GuestRequest|RoomIssue $model): void
    {
        $chat = config('services.telegram.chat_id');
        if (! $this->api->enabled() || blank($chat)) {
            return;
        }
        defer(function () use ($model, $chat) {
            [$html, $keyboard] = $this->render($model);
            $sent = $this->api->send($chat, $html, $keyboard);
            if (isset($sent['message_id'])) {
                TelegramCard::create($this->key($model) + ['chat_id' => $sent['chat']['id'] ?? $chat, 'message_id' => $sent['message_id']]);
            }
        });
    }

    /** Perbarui semua kartu bot milik permintaan / kendala ini (kartu asli pengguna `origin` dilewati), sesudah jawaban terkirim. */
    public function refresh(GuestRequest|RoomIssue $model): void
    {
        $this->api->enabled() && defer(fn () => $this->refreshNow($model));
    }

    /** Sama, langsung (dipakai middleware yang sudah berjalan sesudah jawaban). */
    public function refreshNow(GuestRequest|RoomIssue $model): void
    {
        if (! $this->api->enabled()) {
            return;
        }
        [$html, $keyboard] = $this->render($model->fresh() ?? $model);
        TelegramCard::where($this->key($model))->where('origin', false)->get()
            ->each(fn (TelegramCard $card) => $this->api->edit($card->chat_id, $card->message_id, $html, $keyboard));
    }

    /** @return array{0: string, 1: array} */
    private function render(GuestRequest|RoomIssue $model): array
    {
        return $model instanceof GuestRequest ? Cards::request($model) : Cards::issue($model);
    }

    /** @return array<string, int> */
    private function key(GuestRequest|RoomIssue $model): array
    {
        return [$model instanceof GuestRequest ? 'guest_request_id' : 'room_issue_id' => $model->getKey()];
    }
}
