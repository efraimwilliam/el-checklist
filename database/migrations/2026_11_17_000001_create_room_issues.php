<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('floor', 30)->nullable();
            $table->string('building', 60)->nullable();
            $table->string('type', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Kategori masalah beserta departemen penanggung jawab bawaannya.
        Schema::create('issue_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Kendala di satu kamar (tamu melapor ke FO, diteruskan ke departemen yang menangani).
        Schema::create('room_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('issue_categories')->nullOnDelete();
            $table->string('source', 20)->default('tamu'); // tamu, staff, inspeksi
            $table->text('description');
            $table->string('priority', 20)->default('biasa'); // biasa, mendesak
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('status', 20)->default('baru'); // baru, dikerjakan, selesai
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['room_id', 'created_at']);
            $table->index('status');
            $table->index(['assigned_department_id', 'status']);
        });

        // Catatan pekerjaan yang sudah dilakukan di kamar, juga jejak teruskan / selesai.
        Schema::create('room_issue_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->string('kind', 20)->default('catatan'); // catatan, selesai, teruskan
            $table->timestamp('created_at')->nullable();
            $table->index(['room_issue_id', 'created_at']);
        });

        // Kategori bawaan; departemen dicocokkan lewat nama (kosong bila belum ada departemennya).
        $department = fn (string $like) => DB::table('departments')->where('name', 'like', $like)->value('id');
        $engineering = $department('Engineering%');
        $housekeeping = $department('Housekeeping%');
        $now = now();
        foreach ([
            ['AC', $engineering], ['Listrik & lampu', $engineering], ['Air & sanitasi', $engineering], ['Perabot', $engineering],
            ['TV & elektronik', $engineering], ['Kebersihan & linen', $housekeeping], ['Minibar & amenities', $housekeeping], ['Lainnya', null],
        ] as $index => [$name, $departmentId]) {
            DB::table('issue_categories')->insert(['name' => $name, 'department_id' => $departmentId, 'sort' => $index + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('room_issue_updates');
        Schema::dropIfExists('room_issues');
        Schema::dropIfExists('issue_categories');
        Schema::dropIfExists('rooms');
    }
};
