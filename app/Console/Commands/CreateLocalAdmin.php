<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Akun sistem Administrator dengan password sendiri di Checklist, untuk login tanpa Portal (mode "Login sendiri").
 * Password diminta lewat terminal (tidak pernah di argumen atau riwayat perintah), lalu disimpan sebagai hash.
 */
class CreateLocalAdmin extends Command
{
    protected $signature = 'checklist:admin-lokal {username : Username akun sistem, mis. admin}';

    protected $description = 'Buat atau ganti password akun Administrator sistem (tanpa pegawai absensi)';

    public function handle(): int
    {
        $username = mb_strtolower(trim($this->argument('username')));

        // Pegawai absensi tidak boleh diambil alih lewat perintah ini: login mereka tetap lewat Absensi / SSO.
        if (Employee::where('username', $username)->whereNotNull('external_id')->exists()) {
            $this->error('Username ini pegawai di Sistem Absensi. Akun sistem hanya untuk username yang tidak ada di absensi.');

            return self::FAILURE;
        }

        $password = $this->secret('Password baru (minimal 8 karakter)');
        if (mb_strlen((string) $password) < 8) {
            $this->error('Password terlalu pendek.');

            return self::FAILURE;
        }
        if ($this->secret('Ulangi password') !== $password) {
            $this->error('Kedua password tidak sama.');

            return self::FAILURE;
        }

        $employee = Employee::firstOrCreate(
            ['username' => $username, 'external_id' => null],
            ['name' => ucfirst($username), 'is_active' => true],
        );
        $user = User::firstOrCreate(['employee_id' => $employee->id], ['role' => Role::Admin, 'is_active' => true]);
        $user->forceFill(['password' => Hash::make($password), 'is_active' => true])->save();

        $this->info("Password {$username} disimpan. Login dengan username dan password ini saat mode SSO dimatikan.");

        return self::SUCCESS;
    }
}
