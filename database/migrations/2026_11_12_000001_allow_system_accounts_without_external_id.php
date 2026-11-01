<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Akun sistem (mis. "admin" dari Sistem Absensi) bukan pegawai di absensi: tidak punya external_id,
        // dan sinkronisasi tidak menyentuh baris tanpa external_id.
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('external_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('external_id')->nullable(false)->change();
        });
    }
};
