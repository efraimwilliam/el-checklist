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
            $url = 'https://api.telegram.org/bot'.config('services.telegram.token').'/'.$method;
            $response = Http::timeout($timeout)->post($url, $params);
            // Batas kirim Telegram (429): tunggu sebentar sesuai permintaannya lalu coba sekali lagi, supaya kartu tidak hilang saat ramai.
            if ($response->status() === 429 && ($wait = (int) $response->json('parameters.retry_after', 0)) >= 1 && $wait <= 5) {
                sleep($wait);
                $response = Http::timeout($timeout)->post($url, $params);
            }
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
    public function send(int|string $chatId, string $html, ?array $keyboard = null, ?int $replyTo = null, bool $preview = false): ?array
    {
        return $this->call('sendMessage', array_filter([
            'chat_id' => $chatId, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => ! $preview,
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

    /** Daftar perintah yang muncul saat pengguna mengetik "/" (grup dan chat pribadi berbeda). */
    public function registerCommands(): void
    {
        $common = [
            ['command' => 'daftar', 'description' => 'Permintaan / kendala yang belum selesai'],
            ['command' => 'respon', 'description' => 'Kecepatan respons hari ini'],
            ['command' => 'tertinggal', 'description' => 'Barang tertinggal yang disimpan'],
            ['command' => 'bantuan', 'description' => 'Cara memakai bot'],
        ];
        $private = [...$common,
            ['command' => 'checklist', 'description' => 'Isi checklist hari ini'],
            ['command' => 'chronology', 'description' => 'Buat Chronology Report'],
            ['command' => 'anggota', 'description' => 'Siapa sudah tertaut (HR / Admin)'],
            ['command' => 'batal', 'description' => 'Batalkan isian yang sedang berjalan'],
        ];
        $this->call('setMyCommands', ['commands' => $common, 'scope' => ['type' => 'all_group_chats']]);
        $this->call('setMyCommands', ['commands' => $private, 'scope' => ['type' => 'all_private_chats']]);
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
