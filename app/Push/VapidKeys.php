<?php

namespace App\Push;

use Minishlink\WebPush\VAPID;
use RuntimeException;

/**
 * Pasangan kunci VAPID (identitas server untuk Web Push). Dari .env bila diisi, selain itu dibuat sekali dan disimpan di
 * storage/app/private/webpush/vapid.json. Kunci publik dibagikan ke browser; yang privat tidak pernah keluar dari server.
 * Mengganti kunci membuat semua langganan lama tidak berlaku (pengguna perlu menyalakan notifikasi lagi).
 */
final class VapidKeys
{
    /** @return array{publicKey: string, privateKey: string} */
    public static function pair(): array
    {
        $public = (string) config('webpush.public_key');
        $private = (string) config('webpush.private_key');
        if ($public !== '' && $private !== '') {
            return ['publicKey' => $public, 'privateKey' => $private];
        }

        $path = self::path();
        if (is_file($path)) {
            $stored = json_decode((string) file_get_contents($path), true);
            if (is_array($stored) && filled($stored['publicKey'] ?? null) && filled($stored['privateKey'] ?? null)) {
                return ['publicKey' => $stored['publicKey'], 'privateKey' => $stored['privateKey']];
            }
        }

        return self::generate($path);
    }

    public static function publicKey(): string
    {
        return self::pair()['publicKey'];
    }

    public static function path(): string
    {
        return storage_path('app/private/webpush/vapid.json');
    }

    /** @return array{publicKey: string, privateKey: string} */
    private static function generate(string $path): array
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Folder kunci notifikasi tidak bisa dibuat: '.$directory);
        }

        // Dua permintaan pertama yang bersamaan memakai kunci yang sama: tulis ke berkas sementara, lalu pasang hanya bila belum ada.
        $keys = VAPID::createVapidKeys();
        $temp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temp, json_encode(['publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']], JSON_PRETTY_PRINT));
        chmod($temp, 0600);
        if (! @link($temp, $path)) {
            // Sudah dibuat permintaan lain: pakai yang itu.
            @unlink($temp);

            return self::pair();
        }
        @unlink($temp);

        return ['publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']];
    }
}
