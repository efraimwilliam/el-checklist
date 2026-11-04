<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

/**
 * Akun Staff uji untuk pengembangan lokal: punya PIN sendiri di Checklist (tanpa Sistem Absensi), untuk mencoba peran Staff.
 * Hanya jalan di lingkungan "local". Bila --pin tidak diberikan, PIN 6 digit dibuat acak dan ditulis ke berkas lokal privat
 * (tidak ditampilkan di terminal dan tidak masuk Git). Login memakai kolom "PIN atau password" yang sama.
 */
class CreateLocalStaff extends Command
{
    protected $signature = 'checklist:staff-lokal {username : Username akun uji, mis. staff.uji} {--departemen= : Nama departemen (awalan), mis. Engineering} {--pin= : PIN 4-8 digit; kosong = acak}';

    protected $description = 'Buat atau ganti akun Staff uji dengan PIN sendiri (khusus lingkungan lokal)';

    public function handle(): int
    {
        if (! $this->laravel->environment('local')) {
            $this->error('Perintah ini hanya untuk lingkungan lokal (APP_ENV=local).');

            return self::FAILURE;
        }

        $username = mb_strtolower(trim($this->argument('username')));
        if (Employee::where('username', $username)->whereNotNull('external_id')->exists()) {
            $this->error('Username ini pegawai di Sistem Absensi. Pakai username lain untuk akun uji.');

            return self::FAILURE;
        }

        $department = null;
        if ($name = $this->option('departemen')) {
            $department = Department::where('name', 'like', $name.'%')->orderBy('id')->first();
            if (! $department) {
                $this->error("Departemen \"{$name}\" tidak ditemukan.");

                return self::FAILURE;
            }
        }

        $generated = $this->option('pin') === null;
        $pin = $generated ? (string) random_int(100000, 999999) : (string) $this->option('pin');
        if (! preg_match('/^\d{4,8}$/', $pin)) {
            $this->error('PIN harus 4 sampai 8 digit angka.');

            return self::FAILURE;
        }

        // Jabatan checklist: jabatan pertama di departemen itu yang punya tugas aktif, supaya Staff langsung bisa membuka checklist.
        $position = $department ? Position::where('department_id', $department->id)->where('is_active', true)->whereHas('tasks', fn ($q) => $q->where('is_active', true))->orderBy('sort')->first() : null;

        $employee = Employee::firstOrCreate(['username' => $username, 'external_id' => null], ['name' => 'Staff Uji '.ucfirst($username), 'is_active' => true]);
        $employee->update(['department_id' => $department?->id, 'checklist_position_id' => $position?->id, 'is_active' => true]);
        $user = User::firstOrCreate(['employee_id' => $employee->id], ['role' => Role::Staff, 'is_active' => true]);
        $user->forceFill(['role' => Role::Staff, 'password' => Hash::make($pin), 'is_active' => true])->save();

        $this->info("Akun Staff \"{$username}\" siap".($department ? " di {$department->name}" : '').'.');

        if ($generated) {
            $dir = storage_path('app/private/akun-uji');
            File::ensureDirectoryExists($dir);
            $file = "{$dir}/{$username}.txt";
            File::put($file, "Username: {$username}\nPIN: {$pin}\n");
            chmod($file, 0600);
            $this->line("Username dan PIN ditulis ke: {$file}");
        }

        return self::SUCCESS;
    }
}
