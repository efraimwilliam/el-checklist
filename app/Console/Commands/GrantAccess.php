<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;

/** Memberi akses pertama (mis. Administrator) dari server, sebelum ada admin yang bisa mengatur dari aplikasi. */
class GrantAccess extends Command
{
    protected $signature = 'checklist:grant {username : Username absensi (jalankan attendance:sync dulu)} {role=admin : admin, hr, gm, hod, staff}';

    protected $description = 'Beri pegawai akses aplikasi dengan peran tertentu';

    public function handle(): int
    {
        $role = Role::tryFrom($this->argument('role'));
        $employee = Employee::where('username', mb_strtolower($this->argument('username')))->first();
        if (! $role || ! $employee) {
            $this->error(! $role ? 'Peran tidak dikenal.' : 'Pegawai tidak ditemukan. Jalankan attendance:sync dulu.');

            return self::FAILURE;
        }
        User::updateOrCreate(['employee_id' => $employee->id], ['role' => $role, 'is_active' => true]);
        $this->info("{$employee->name} sekarang {$role->label()}.");

        return self::SUCCESS;
    }
}
