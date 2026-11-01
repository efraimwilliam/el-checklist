<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Jabatan checklist kini diatur satu tempat: pegawai (Tim → Jabatan). Pilihan lama di akun dipindahkan, hanya bila jabatan pegawainya masih kosong.
        DB::statement('UPDATE employees e JOIN users u ON u.employee_id = e.id SET e.checklist_position_id = u.position_id WHERE e.checklist_position_id IS NULL AND u.position_id IS NOT NULL');
    }

    public function down(): void
    {
        // Data yang dipindahkan tidak bisa dibedakan dari pilihan baru; users.position_id tidak diubah, jadi tidak ada yang hilang.
    }
};
