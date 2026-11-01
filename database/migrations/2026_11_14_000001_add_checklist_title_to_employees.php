<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Teks jabatan Absensi saat jabatan checklist terakhir dikonfirmasi. Kalau teks di Absensi kemudian berubah, pegawai ditandai "perlu dicek".
        Schema::table('employees', function (Blueprint $table) {
            $table->string('checklist_title')->nullable()->after('checklist_position_id');
        });

        // Jabatan yang sudah diatur dianggap benar untuk teks Absensi saat ini.
        DB::statement('UPDATE employees SET checklist_title = position_title WHERE checklist_position_id IS NOT NULL AND position_title IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('checklist_title');
        });
    }
};
