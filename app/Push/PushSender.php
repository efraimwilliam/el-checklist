<?php

namespace App\Push;

use App\Models\PushSubscription;

/** Pengirim satu notifikasi ke satu perangkat. Dipisah dari pustaka web-push supaya tes tidak menghubungi layanan push sungguhan. */
interface PushSender
{
    public const SENT = 'sent';

    /** Langganan sudah tidak berlaku (aplikasi dihapus / izin dicabut): hapus dari database. */
    public const GONE = 'gone';

    public const FAILED = 'failed';

    /**
     * @param  array<string, mixed>  $payload  title, body, url, tag
     * @return self::SENT|self::GONE|self::FAILED
     */
    public function send(PushSubscription $subscription, array $payload): string;
}
