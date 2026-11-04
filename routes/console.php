<?php

use App\Health\SystemHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Data pegawai mengikuti Sistem Absensi. Server perlu menjalankan "php artisan schedule:run" setiap menit.
Schedule::command('attendance:sync')->hourly()->withoutOverlapping();

// Checklist kosong yang ditinggalkan (tanpa satu pun isian) dibersihkan tiap hari.
Schedule::command('checklist:prune-empty')->dailyAt('03:00');

// Foto bukti yang melewati masa simpan (Pengaturan) dihapus tiap hari.
Schedule::command('checklist:prune-photos')->dailyAt('03:30')->withoutOverlapping();

// Pembuatan otomatis checklist harian per departemen (jam diatur HOD / HR / Administrator di halaman Isi checklist).
Schedule::command('checklist:auto-assign')->everyFiveMinutes()->withoutOverlapping();

// Ringkasan mingguan (7 hari sampai kemarin) setiap Senin pagi, WITA. Pengiriman email menunggu alamat GM.
Schedule::command('laporan:mingguan')->weeklyOn(1, '07:00')->timezone('Asia/Makassar')->withoutOverlapping();

// Log aplikasi yang sudah lewat masa simpan (config/audit.php) dihapus setiap malam.
Schedule::command('audit:prune')->dailyAt('03:40');

// Denyut penjadwal: halaman Kesehatan sistem memastikan "schedule:run" masih berjalan tiap menit.
Schedule::call(fn () => Cache::forever(SystemHealth::SCHEDULER_CACHE, now()->toIso8601String()))->everyMinute()->name('scheduler-heartbeat');

// Ringkasan harian untuk laporan bulanan (menghitung ulang 7 hari terakhir).
Schedule::command('laporan:snapshot')->dailyAt('03:50')->withoutOverlapping();
