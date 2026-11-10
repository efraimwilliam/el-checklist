<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kendala tidak lagi hanya di kamar: lokasi bisa kamar (room_id) atau tempat lain (teks bebas di `location`).
        Schema::table('room_issues', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
        });
        Schema::table('room_issues', function (Blueprint $table) {
            $table->unsignedBigInteger('room_id')->nullable()->change();
            $table->foreign('room_id')->references('id')->on('rooms')->restrictOnDelete();
            $table->string('location', 120)->nullable()->after('room_id');
            $table->index('location');
            // Diteruskan ke: departemen (bawaan), satu jabatan, atau satu pegawai. Departemennya tetap diisi untuk laporan dan penyaring.
            $table->foreignId('assigned_position_id')->nullable()->after('assigned_department_id')->constrained('positions')->nullOnDelete();
            $table->foreignId('assigned_employee_id')->nullable()->after('assigned_position_id')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('room_issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_employee_id');
            $table->dropConstrainedForeignId('assigned_position_id');
            $table->dropIndex(['location']);
            $table->dropColumn('location');
        });
    }
};
