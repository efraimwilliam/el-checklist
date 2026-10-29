<?php

// Koneksi ke Sistem Absensi (sumber login username + PIN dan data master pegawai/departemen).
// Semua permintaan dari server ini; browser tidak pernah menghubungi API absensi langsung.
return [
    'url' => rtrim((string) env('ATTENDANCE_API_URL'), '/'),
    'token' => env('ATTENDANCE_API_TOKEN'),

    'paths' => [
        'login' => env('ATTENDANCE_API_LOGIN_PATH', '/api/v1/tasks/auth/verify-pin'),
        'login_password' => env('ATTENDANCE_API_LOGIN_PASSWORD_PATH', '/api/v1/tasks/auth/verify-password'),
        'employees' => env('ATTENDANCE_API_EMPLOYEES_PATH', '/api/v1/tasks/employees'),
        'departments' => env('ATTENDANCE_API_DEPARTMENTS_PATH', '/api/v1/tasks/departments'),
        'schedules' => env('ATTENDANCE_API_SCHEDULES_PATH', '/api/v1/tasks/schedules'),
    ],

    // Detik.
    'connect_timeout' => (int) env('ATTENDANCE_API_CONNECT_TIMEOUT', 3),
    'timeout' => (int) env('ATTENDANCE_API_TIMEOUT', 8),

    // Verifikasi sertifikat TLS. Jangan pernah false di produksi (AppServiceProvider menolak saat boot).
    'verify_tls' => (bool) env('ATTENDANCE_API_VERIFY_TLS', true),

    // Header tambahan bila API absensi memintanya, mis. versi API.
    'headers' => [],
];
