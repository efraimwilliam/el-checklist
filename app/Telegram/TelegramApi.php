<?php

namespace App\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Klien tipis Bot API Telegram. Semua metode aman dipanggil walau bot belum dikonfigurasi (mengembalikan null). */
class TelegramApi
{
    public function enabled(): bool
    {
        return filled(config('services.telegram.token'));
    }

    /** @param array<string, mixed> $params @return array<string, mixed>|null isi `result`, null bila gagal */
    public function call(string $method, array $params = [], int $timeout = 5): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        try {
            $response = Http::timeout($timeout)->post('https://api.telegram.org/bot'.config('services.telegram.token').'/'.$method, $params);
        } catch (\Throwable $e) {
            Log::warning('Telegram '.$method.' gagal: '.$e->getMessage());

            return null;
        }
        if (! $response->json('ok')) {
            Log::warning('Telegram '.$method.' ditolak: '.$response->json('description'));

            return null;
        }

        $result = $response->json('result');

        return is_array($result) ? $result : ['ok' => true];
    }

    /** @param list<list<array{text: string, callback_data: string}>>|null $keyboard @return array<string, mixed>|null */
    public function send(int|string $chatId, string $html, ?array $keyboard = null, ?int $replyTo = null): ?array
    {
        return $this->call('sendMessage', array_filter([
            'chat_id' => $chatId, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
            'reply_markup' => $keyboard !== null ? ['inline_keyboard' => $keyboard] : null,
            'reply_parameters' => $replyTo ? ['message_id' => $replyTo, 'allow_sending_without_reply' => true] : null,
        ], fn ($value) => $value !== null));
    }

    /** Ganti isi kartu; tombol kosong ([]) menghapus tombolnya. */
    public function edit(int|string $chatId, int $messageId, string $html, array $keyboard = []): ?array
    {
        return $this->call('editMessageText', [
            'chat_id' => $chatId, 'message_id' => $messageId, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ]);
    }

    /** Unduh berkas (foto) dari Telegram: isi mentah, atau null bila gagal. */
    public function download(string $fileId): ?string
    {
        $path = $this->call('getFile', ['file_id' => $fileId])['file_path'] ?? null;
        if (! $path) {
            return null;
        }
        try {
            $response = Http::timeout(15)->get('https://api.telegram.org/file/bot'.config('services.telegram.token').'/'.$path);
        } catch (\Throwable $e) {
            Log::warning('Telegram unduh berkas gagal: '.$e->getMessage());

            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    public function answer(string $callbackId, string $text = '', bool $alert = false): void
    {
        $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $callbackId, 'text' => $text !== '' ? mb_substr($text, 0, 190) : null, 'show_alert' => $alert ?: null]));
    }
}
