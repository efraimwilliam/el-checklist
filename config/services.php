<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Portal SSO (login sekali untuk semua sistem). Mati selama SSO_CLIENT_ID kosong: login username + PIN tetap dipakai.
    'sso' => [
        'enabled' => (bool) env('SSO_ENABLED', true),
        'portal_url' => rtrim((string) env('SSO_PORTAL_URL'), '/'),
        'client_id' => env('SSO_CLIENT_ID'),
        'client_secret' => env('SSO_CLIENT_SECRET'),
        // Seberapa sering (menit) sesi dicek ulang ke portal: akun nonaktif / akses dicabut / keluar dari portal.
        'check_minutes' => (int) env('SSO_CHECK_MINUTES', 5),
        // Username akun sistem di Portal (bukan pegawai absensi) yang boleh masuk lewat SSO, dipisah koma. Kosong = tidak ada.
        'system_accounts' => array_values(array_filter(array_map('trim', explode(',', mb_strtolower((string) env('SSO_SYSTEM_ACCOUNTS', '')))))),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
