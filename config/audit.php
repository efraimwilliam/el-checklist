<?php

return [
    // Log aplikasi lebih tua dari jumlah hari ini dihapus setiap malam (php artisan audit:prune).
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 180),
];
