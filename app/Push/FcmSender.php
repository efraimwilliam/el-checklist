<?php

namespace App\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Firebase Cloud Messaging (HTTP v1). Masuk dengan service account (JWT RS256 -> token akses OAuth, disimpan di cache ~50 menit).
 * Berkas service account rahasia: `config('services.fcm.credentials')`, di luar Git. Tidak ada berkas = tidak mengirim apa pun.
 */
final class FcmSender implements AppPushSender
{
    /** Alasan kiriman terakhir gagal (untuk `aplikasi:cek-notif`); null = berhasil. */
    public ?string $lastError = null;

    public function send(string $token, array $message): string
    {
        $this->lastError = null;
        $credentials = $this->credentials();
        if ($credentials === null) {
            $this->lastError = 'Berkas service account Firebase tidak ada / tidak valid: '.config('services.fcm.credentials');
            Log::warning('FCM: '.$this->lastError);

            return self::FAILED;
        }

        try {
            $response = Http::withToken($this->accessToken($credentials))->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", ['message' => [
                    'token' => $token,
                    'notification' => ['title' => $message['title'], 'body' => $message['body']],
                    'data' => $message['data'],
                    // Saluran "tugas" dibuat aplikasi; tag sama = notifikasi lama diganti (mis. permintaan kamar yang sama).
                    'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'tugas', 'tag' => $message['tag']]],
                ]]);
        } catch (Throwable $e) {
            $this->lastError = 'Firebase tidak bisa dihubungi: '.$e->getMessage();
            Log::warning('FCM gagal dihubungi: '.$e->getMessage());

            return self::FAILED;
        }

        if ($response->successful()) {
            return self::SENT;
        }
        if ($response->status() === 404 || str_contains($response->body(), 'UNREGISTERED')) {
            $this->lastError = 'Token HP sudah tidak berlaku (aplikasi dihapus / data dibersihkan).';

            return self::GONE;
        }
        $this->lastError = 'Firebase menolak ('.$response->status().'): '.mb_substr($response->body(), 0, 300);
        if ($response->status() === 401) {
            Cache::forget('fcm.access_token');
        }
        Log::warning('FCM menolak notifikasi', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);

        return self::FAILED;
    }

    /** @return array{project_id: string, client_email: string, private_key: string, token_uri: string}|null */
    private function credentials(): ?array
    {
        $path = (string) config('services.fcm.credentials');
        $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($json) && isset($json['project_id'], $json['client_email'], $json['private_key']) ? $json + ['token_uri' => 'https://oauth2.googleapis.com/token'] : null;
    }

    private function accessToken(array $credentials): string
    {
        return Cache::remember('fcm.access_token', 3000, function () use ($credentials) {
            $encode = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            $now = time();
            $unsigned = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $credentials['token_uri'],
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256) || throw new \RuntimeException('Kunci service account Firebase tidak bisa dipakai.');

            return Http::asForm()->timeout(10)->post($credentials['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.$encode($signature),
            ])->throw()->json('access_token');
        });
    }
}
