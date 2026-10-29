<?php

use Illuminate\Support\Facades\Schedule;

// Data pegawai mengikuti Sistem Absensi. Server perlu menjalankan "php artisan schedule:run" setiap menit.
Schedule::command('attendance:sync')->hourly()->withoutOverlapping();

// Checklist kosong yang ditinggalkan (tanpa satu pun isian) dibersihkan tiap hari.
Schedule::command('checklist:prune-empty')->dailyAt('03:00');

// Foto bukti yang melewati masa simpan (Pengaturan) dihapus tiap hari.
Schedule::command('checklist:prune-photos')->dailyAt('03:30')->withoutOverlapping();

// Pembuatan otomatis checklist harian per departemen (jam diatur HOD / HR / Administrator di halaman Isi checklist).
Schedule::command('checklist:auto-assign')->everyFiveMinutes()->withoutOverlapping();
