<?php

namespace App\Attendance;

use App\Models\Employee;
use App\Support\PhotoReencoder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Salinan lokal foto profil pegawai dari Sistem Absensi (`employee-photos/{employee}.jpg`, disk privat `local`).
 * Hanya pegawai aktif yang `photo_version` dari Absensi berbeda dengan `photo_cached_version` yang diambil ulang;
 * foto yang dihapus di Absensi ikut dihapus di sini. Gagal mengambil = dicoba lagi pada sinkron berikutnya.
 */
class EmployeePhotoSync
{
    public const FOLDER = 'employee-photos';

    /** Sisi terpanjang salinan lokal (avatar obrolan paling besar 96 px, cukup untuk layar rapat). */
    public const MAX_EDGE = 320;

    /** Batas unduhan per sinkron supaya `attendance:sync` tetap singkat; sisanya menyusul jam berikutnya. */
    public const PER_RUN = 200;

    public function __construct(private AttendanceApiService $api) {}

    public static function path(Employee $employee): string
    {
        return self::FOLDER.'/'.$employee->id.'.jpg';
    }

    /** @return array{updated: int, removed: int, failed: int} */
    public function refresh(int $limit = self::PER_RUN): array
    {
        $disk = Storage::disk('local');
        $result = ['updated' => 0, 'removed' => 0, 'failed' => 0];

        // Foto dihapus di Absensi (atau pegawai nonaktif): hapus salinannya.
        Employee::whereNotNull('photo_cached_version')
            ->where(fn ($q) => $q->whereNull('photo_version')->orWhere('is_active', false))
            ->each(function (Employee $employee) use ($disk, &$result) {
                $disk->delete(self::path($employee));
                Employee::whereKey($employee->id)->update(['photo_cached_version' => null]); // tanpa log aplikasi
                $result['removed']++;
            });

        $pending = Employee::where('is_active', true)->whereNotNull('external_id')->whereNotNull('photo_version')
            ->where(fn ($q) => $q->whereNull('photo_cached_version')->orWhereColumn('photo_cached_version', '!=', 'photo_version'))
            ->orderBy('id')->limit($limit)->get();

        foreach ($pending as $employee) {
            try {
                $contents = $this->api->employeePhoto($employee->external_id);
            } catch (AttendanceUnavailable $e) {
                Log::warning('Employee photo fetch failed', ['employee' => $employee->id, 'error' => $e->getMessage()]);
                $result['failed']++;

                break; // Absensi bermasalah: jangan ulangi untuk setiap pegawai
            }

            if ($contents === null) { // versi sudah basi (foto baru saja dihapus): tunggu sinkron berikutnya
                $disk->delete(self::path($employee));
                Employee::whereKey($employee->id)->update(['photo_cached_version' => null]);

                continue;
            }

            try {
                $jpeg = PhotoReencoder::fromString($contents, maxEdge: self::MAX_EDGE);
            } catch (ValidationException) {
                Log::warning('Employee photo from attendance is not an image', ['employee' => $employee->id]);
                $result['failed']++;

                continue;
            }

            $disk->put(self::path($employee), $jpeg);
            Employee::whereKey($employee->id)->update(['photo_cached_version' => $employee->photo_version]);
            $result['updated']++;
        }

        return $result;
    }
}
