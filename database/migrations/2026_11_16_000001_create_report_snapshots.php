<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ringkasan harian per departemen dan jabatan, disimpan terpisah supaya angka lama tetap ada
        // walau checklist atau foto dihapus; dasar laporan bulanan.
        Schema::create('report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('position_name');
            $table->unsignedInteger('checklists')->default(0);
            $table->unsignedInteger('items')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('ongoing')->default(0);
            $table->unsignedInteger('pending')->default(0);
            $table->unsignedInteger('blank')->default(0);
            $table->timestamps();
            $table->unique(['date', 'department_id', 'position_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_snapshots');
    }
};
