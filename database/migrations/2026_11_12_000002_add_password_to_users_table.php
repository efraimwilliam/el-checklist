<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hanya untuk akun sistem (tanpa data pegawai di absensi), dibuat lewat checklist:admin-lokal. Pegawai tetap memakai Absensi.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->after('sso_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
