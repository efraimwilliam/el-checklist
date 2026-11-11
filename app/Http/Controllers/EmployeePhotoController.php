<?php

namespace App\Http\Controllers;

use App\Attendance\EmployeePhotoSync;
use App\Models\Employee;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Foto profil pegawai (salinan dari Sistem Absensi) untuk avatar obrolan; hanya pengguna login dengan gate `chat`. */
class EmployeePhotoController extends Controller
{
    public function __invoke(Employee $employee): Response
    {
        $disk = Storage::disk('local');
        $path = EmployeePhotoSync::path($employee);
        abort_unless($employee->photo_cached_version && $disk->exists($path), 404);

        // URL memuat ?v=versi yang berganti tiap foto diganti, jadi boleh disimpan lama di browser.
        return $disk->response($path, 'foto-'.$employee->id.'.jpg', [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }
}
