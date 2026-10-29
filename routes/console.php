<?php

use Illuminate\Support\Facades\Schedule;

// Data pegawai mengikuti Sistem Absensi. Server perlu menjalankan "php artisan schedule:run" setiap menit.
Schedule::command('attendance:sync')->hourly()->withoutOverlapping();

// Checklist kosong yang ditinggalkan (tanpa satu pun isian) dibersihkan tiap hari.
Schedule::command('checklist:prune-empty')->dailyAt('03:00');
