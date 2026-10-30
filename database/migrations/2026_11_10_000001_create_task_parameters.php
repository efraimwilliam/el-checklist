<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parameter isian per tugas (status, angka, meter, pilihan, centang, teks). Satu tugas bisa punya banyak parameter.
 * Saat checklist dibuat, parameter disalin ke item (checklist_item_parameters) seperti judul dan standar tugas.
 * Template parameter disimpan supaya bisa dipakai lagi di tugas lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parameter_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('type', 20);
            $table->string('unit', 30)->nullable();
            $table->decimal('min_value', 12, 2)->nullable();
            $table->decimal('max_value', 12, 2)->nullable();
            $table->text('options')->nullable();
            $table->string('normal_option', 100)->nullable();
            $table->timestamps();
            $table->unique(['name', 'type']);
        });

        Schema::create('task_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('name', 120);
            $table->string('type', 20)->default('status');
            $table->string('unit', 30)->nullable();
            $table->decimal('min_value', 12, 2)->nullable();
            $table->decimal('max_value', 12, 2)->nullable();
            $table->text('options')->nullable();
            $table->string('normal_option', 100)->nullable();
            $table->timestamps();
            $table->index(['task_id', 'sort']);
        });

        Schema::create('checklist_item_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_parameter_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('name', 120);
            $table->string('type', 20)->default('status');
            $table->string('unit', 30)->nullable();
            $table->decimal('min_value', 12, 2)->nullable();
            $table->decimal('max_value', 12, 2)->nullable();
            $table->text('options')->nullable();
            $table->string('normal_option', 100)->nullable();
            $table->string('value', 255)->nullable();
            $table->timestamps();
            $table->index(['checklist_item_id', 'sort']);
        });

        // Setiap tugas yang sudah ada mendapat parameter Status (Done / Ongoing / Pending) seperti sebelumnya.
        DB::statement("INSERT INTO task_parameters (task_id, sort, name, type, created_at, updated_at) SELECT id, 1, 'Status', 'status', NOW(), NOW() FROM tasks");
        DB::statement("INSERT INTO checklist_item_parameters (checklist_item_id, task_parameter_id, sort, name, type, value, created_at, updated_at) SELECT ci.id, tp.id, 1, 'Status', 'status', ci.status, NOW(), NOW() FROM checklist_items ci LEFT JOIN task_parameters tp ON tp.task_id = ci.task_id AND tp.sort = 1");
        DB::statement("INSERT IGNORE INTO parameter_templates (name, type, created_at, updated_at) VALUES ('Status', 'status', NOW(), NOW())");
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_item_parameters');
        Schema::dropIfExists('task_parameters');
        Schema::dropIfExists('parameter_templates');
    }
};
