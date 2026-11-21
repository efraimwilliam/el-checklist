<?php

namespace App\Console\Commands;

use App\Telegram\TelegramApi;
use Illuminate\Console\Command;

/** Mendaftarkan alamat webhook ke Telegram: php artisan telegram:webhook https://domain.anda (tanpa argumen = tampilkan status). */
class TelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook {url? : alamat dasar aplikasi, mis. https://checklist.example.com} {--hapus : lepas webhook}';

    protected $description = 'Pasang / lepas / lihat webhook bot Telegram';

    public function handle(TelegramApi $api): int
    {
        if (! $api->enabled()) {
            $this->error('TELEGRAM_BOT_TOKEN belum diisi di .env.');

            return self::FAILURE;
        }
        if ($this->option('hapus')) {
            $this->info($api->call('deleteWebhook') ? 'Webhook dilepas.' : 'Gagal melepas webhook.');

            return self::SUCCESS;
        }
        if ($url = $this->argument('url')) {
            $secret = (string) config('services.telegram.secret');
            if ($secret === '' || ! str_starts_with($url, 'https://')) {
                $this->error('Isi TELEGRAM_WEBHOOK_SECRET di .env (acak, huruf / angka / _ / -) dan pakai alamat https://.');

                return self::FAILURE;
            }
            $ok = $api->call('setWebhook', ['url' => rtrim($url, '/').'/api/telegram/webhook', 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => true]);
            $this->line($ok ? 'Webhook terpasang.' : 'Gagal memasang webhook (lihat storage/logs).');
            $ok && $api->registerCommands();
        }
        $info = $api->call('getWebhookInfo');
        $this->line('Webhook: '.($info['url'] ?? '-').' | menunggu: '.($info['pending_update_count'] ?? '-').(isset($info['last_error_message']) ? ' | galat terakhir: '.$info['last_error_message'] : ''));

        return self::SUCCESS;
    }
}
