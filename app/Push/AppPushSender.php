<?php

namespace App\Push;

/** Pengirim satu notifikasi ke satu HP (aplikasi Android). Dipisah supaya tes tidak menghubungi Firebase. */
interface AppPushSender
{
    public const SENT = 'sent';

    /** Token sudah tidak berlaku (aplikasi dihapus / data dibersihkan): dilepas dari database. */
    public const GONE = 'gone';

    public const FAILED = 'failed';

    /**
     * @param  array{title: string, body: string, data: array<string, string>, tag: string}  $message
     * @return self::SENT|self::GONE|self::FAILED
     */
    public function send(string $token, array $message): string;
}
