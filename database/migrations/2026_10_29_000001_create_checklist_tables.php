<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Templat tugas per jabatan.
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->string('section')->nullable(); // "Bagian"
            $table->text('title');
            $table->text('standard')->nullable(); // "Cara / Standar"
            $table->text('remarks')->nullable(); // "Keterangan"
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Checklist harian seorang pegawai. Departemen, jabatan dan isi tugas disalin supaya riwayat tetap utuh
        // walau pegawai pindah departemen, keluar, atau templat tugas diubah.
        Schema::create('checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->string('position_name');
            $table->date('date');
            $table->string('status', 20)->default('draft'); // draft, submitted, approved, returned
            $table->text('note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
            $table->index(['department_id', 'date']);
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('section')->nullable();
            $table->text('title');
            $table->text('standard')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->string('status', 20)->nullable(); // done, ongoing, pending
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('checklists');
        Schema::dropIfExists('tasks');
    }
};
