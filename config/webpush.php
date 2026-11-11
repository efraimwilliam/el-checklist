<?php

// Notifikasi obrolan (Web Push). Kunci VAPID dibuat otomatis sekali dan disimpan di storage/app/private/webpush/vapid.json
// (di luar Git, izin 600). Isi WEBPUSH_PUBLIC_KEY / WEBPUSH_PRIVATE_KEY di .env hanya bila ingin memakai kunci sendiri.
return [
    'public_key' => env('WEBPUSH_PUBLIC_KEY'),
    'private_key' => env('WEBPUSH_PRIVATE_KEY'),

    // Pengenal pengirim untuk layanan push: alamat https situs ini (atau mailto:).
    'subject' => env('WEBPUSH_SUBJECT', env('APP_URL', 'https://localhost')),

    // Detik pesan masih boleh menunggu bila HP sedang mati / tidak ada sinyal.
    'ttl' => (int) env('WEBPUSH_TTL', 3600),
    'timeout' => (int) env('WEBPUSH_TIMEOUT', 6),

    // Perangkat maksimal per pengguna (yang terlama dibuang).
    'max_per_user' => 10,

    // Detik sesudah polling terakhir pengguna masih dianggap "sedang membuka obrolan itu" (tidak dikirimi push).
    'presence_seconds' => 12,
];
