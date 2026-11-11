<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Foto profil pegawai dari Sistem Absensi: versi yang dilaporkan Absensi dan versi salinan lokal yang tersimpan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('photo_version', 32)->nullable()->after('position_title');
            $table->string('photo_cached_version', 32)->nullable()->after('photo_version');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['photo_version', 'photo_cached_version']);
        });
    }
};
