<?php

namespace App\Console\Commands;

use App\Attendance\AttendanceUnavailable;
use App\Attendance\EmployeePhotoSync;
use App\Attendance\EmployeeSyncService;
use Illuminate\Console\Command;

class SyncEmployees extends Command
{
    protected $signature = 'attendance:sync';

    protected $description = 'Sinkron departemen & pegawai dari Sistem Absensi';

    public function handle(EmployeeSyncService $sync, EmployeePhotoSync $photos): int
    {
        try {
            $result = $sync->sync();
        } catch (AttendanceUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Departemen: {$result['departments']}, pegawai: {$result['employees']}, dinonaktifkan: {$result['deactivated']}.");

        $photo = $photos->refresh();
        $this->info("Foto pegawai: {$photo['updated']} diperbarui, {$photo['removed']} dihapus, {$photo['failed']} gagal.");

        return self::SUCCESS;
    }
}
