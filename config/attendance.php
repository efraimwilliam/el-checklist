<?php

// Koneksi ke Sistem Absensi (sumber login username + PIN dan data master pegawai/departemen).
// Semua permintaan dari server ini; browser tidak pernah menghubungi API absensi langsung.
return [
    'url' => rtrim((string) env('ATTENDANCE_API_URL'), '/'),
    'token' => env('ATTENDANCE_API_TOKEN'),

    'paths' => [
        'login' => env('ATTENDANCE_API_LOGIN_PATH', '/api/v1/tasks/auth/verify-pin'),
        'employees' => env('ATTENDANCE_API_EMPLOYEES_PATH', '/api/v1/tasks/employees'),
        'departments' => env('ATTENDANCE_API_DEPARTMENTS_PATH', '/api/v1/tasks/departments'),
    ],

    // Detik.
    'connect_timeout' => (int) env('ATTENDANCE_API_CONNECT_TIMEOUT', 3),
    'timeout' => (int) env('ATTENDANCE_API_TIMEOUT', 8),

    // Verifikasi sertifikat TLS. Jangan pernah false di produksi (AppServiceProvider menolak saat boot).
    'verify_tls' => (bool) env('ATTENDANCE_API_VERIFY_TLS', true),

    // Header tambahan bila API absensi memintanya, mis. versi API.
    'headers' => [],

    // Pintu masuk PIN di aplikasi absensi (staff tidak punya akses dashboard). Bawaan: {ATTENDANCE_API_URL}/absen/pin/mulai, yang langsung membuka
    // menu PIN memakai QR yang tersimpan di HP (bila belum pernah scan: diminta scan dulu), lalu mengembalikan pegawai ke halaman login ini.
    // Absensi hanya mau kembali ke alamat yang ada di TASKS_URL (.env Absensi). Isi ATTENDANCE_PIN_HELP_URL bila alamat untuk browser berbeda.
    'pin_help_url' => env('ATTENDANCE_PIN_HELP_URL') ?: (env('ATTENDANCE_API_URL') ? rtrim((string) env('ATTENDANCE_API_URL'), '/').'/absen/pin/mulai' : null),
];
