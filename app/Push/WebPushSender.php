<?php

namespace App\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

final class WebPushSender implements PushSender
{
    private ?WebPush $client = null;

    public function send(PushSubscription $subscription, array $payload): string
    {
        try {
            $report = $this->client()->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => $subscription->content_encoding,
                ]),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ['TTL' => (int) config('webpush.ttl'), 'urgency' => 'high', 'topic' => $this->topic($payload['tag'] ?? null)],
            );
        } catch (Throwable $e) {
            Log::warning('Push gagal dikirim: '.$e->getMessage());

            return self::FAILED;
        }

        if ($report->isSuccess()) {
            return self::SENT;
        }
        if ($report->isSubscriptionExpired()) {
            return self::GONE;
        }
        Log::warning('Push ditolak layanan push: '.$report->getReason());

        return self::FAILED;
    }

    private function client(): WebPush
    {
        $keys = VapidKeys::pair();

        // Klien PSR-18 dengan batas waktu: layanan push yang lambat tidak boleh menahan proses terlalu lama.
        return $this->client ??= new WebPush(
            ['VAPID' => ['subject' => config('webpush.subject'), 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']]],
            [],
            new \GuzzleHttp\Client(['timeout' => (int) config('webpush.timeout'), 'connect_timeout' => 3, 'http_errors' => false]),
        );
    }

    /** Topik push (maks 32 karakter URL-safe): pesan baru di obrolan yang sama menggantikan yang belum sempat terkirim. */
    private function topic(?string $tag): string
    {
        return substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $tag) ?: 'chat', 0, 32);
    }
}
