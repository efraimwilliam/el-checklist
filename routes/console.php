<?php

use Illuminate\Support\Facades\Schedule;

// Data pegawai mengikuti Sistem Absensi. Server perlu menjalankan "php artisan schedule:run" setiap menit.
Schedule::command('attendance:sync')->hourly()->withoutOverlapping();
