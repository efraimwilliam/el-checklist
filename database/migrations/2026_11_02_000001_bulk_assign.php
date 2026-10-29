<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jabatan checklist tiap pegawai, diatur sekali oleh HOD, supaya checklist harian bisa dibuatkan sekaligus.
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('checklist_position_id')->nullable()->after('custom_tasks')->constrained('positions')->nullOnDelete();
        });

        // Pembuatan otomatis per departemen: jam (WITA) dan tanggal terakhir yang sudah berhasil dijalankan.
        Schema::table('departments', function (Blueprint $table) {
            $table->time('auto_assign_at')->nullable()->after('is_active');
            $table->date('auto_assigned_on')->nullable()->after('auto_assign_at');
        });

        // Checklist yang dibuatkan sekaligus (bukan dimulai manual): tidak ikut dibersihkan walau masih kosong ("Belum diisi").
        Schema::table('checklists', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable()->after('filled_by');
        });
    }

    public function down(): void
    {
        Schema::table('checklists', fn (Blueprint $table) => $table->dropColumn('assigned_at'));
        Schema::table('departments', fn (Blueprint $table) => $table->dropColumn(['auto_assign_at', 'auto_assigned_on']));
        Schema::table('employees', fn (Blueprint $table) => $table->dropConstrainedForeignId('checklist_position_id'));
    }
};
