<?php

namespace App\Console\Commands;

use App\Telegram\TelegramApi;
use App\Telegram\TelegramHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bot Telegram TANPA alamat publik: server mengambil pesan sendiri dari Telegram (long polling, hanya koneksi KELUAR ke api.telegram.org).
 * Untuk server yang hanya ada di jaringan hotel (webhook ditolak Telegram: "IP address ... is reserved"). Jalankan terus-menerus lewat
 * systemd (lihat DEPLOY.md 13c). Tidak bisa dipakai bersamaan dengan webhook: perintah ini melepas webhook saat mulai.
 */
class TelegramPoll extends Command
{
    private const OFFSET = 'telegram.poll.offset';

    protected $signature = 'telegram:poll {--sekali : ambil satu putaran lalu berhenti (untuk uji)}';

    protected $description = 'Ambil pesan Telegram terus-menerus (long polling) dan proses seperti webhook';

    public function handle(TelegramApi $api): int
    {
        if (! $api->enabled()) {
            $this->error('TELEGRAM_BOT_TOKEN belum diisi di .env.');

            return self::FAILURE;
        }
        $api->call('deleteWebhook'); // getUpdates ditolak Telegram selama webhook terpasang
        $api->registerCommands(); // menu "/" di Telegram diperbarui tiap bot dimulai ulang (deploy)
        $once = (bool) $this->option('sekali');
        $this->info('Bot Telegram mengambil pesan'.($once ? ' (satu putaran)' : ' terus-menerus (Ctrl+C untuk berhenti)').'.');

        do {
            $updates = $api->call('getUpdates', [
                'offset' => (int) Cache::get(self::OFFSET, 0), 'timeout' => $once ? 0 : 50, 'allowed_updates' => ['message', 'callback_query'],
            ], $once ? 10 : 65);
            if ($updates === null) {
                $once || sleep(5); // jaringan / Telegram bermasalah: coba lagi

                continue;
            }
            foreach ($updates as $update) {
                $this->process($update);
                Cache::forever(self::OFFSET, ((int) $update['update_id']) + 1);
            }
        } while (! $once);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $update */
    private function process(array $update): void
    {
        $started = microtime(true);
        try {
            // Proses panjang: sambungan basis data bisa putus saat menganggur (disambung ulang bila perlu), dan cache per-permintaan
            // (kamus, pengaturan) harus segar.
            try {
                DB::select('select 1');
            } catch (\Throwable) {
                DB::reconnect();
            }
            app()->forgetScopedInstances();
            app(TelegramHandler::class)->handle($update);
        } catch (\Throwable $e) {
            report($e);
        } finally {
            // `defer` (kartu ke grup, pembaruan kartu) biasanya jalan di akhir permintaan; di sini tidak ada, jadi jalankan sekarang.
            app(DeferredCallbackCollection::class)->invoke();
            // Antrean diproses satu per satu: pesan yang lambat menunda semua di belakangnya, jadi catat supaya terlihat di log.
            ($seconds = microtime(true) - $started) > 2 && \Illuminate\Support\Facades\Log::warning('Telegram: satu update diproses '.round($seconds, 1).' detik (update '.($update['update_id'] ?? '?').')');
        }
    }
}
