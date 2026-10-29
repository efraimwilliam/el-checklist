<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Data master dari Sistem Absensi (external_id = id di absensi). Kode departemen dipakai juga oleh impor workbook.
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('external_id')->nullable()->unique();
            $table->string('code', 20)->nullable()->unique();
            $table->string('name');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('external_id')->unique();
            $table->string('username')->index();
            $table->string('name');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('position_title')->nullable(); // jabatan menurut absensi (teks bebas)
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        // Jabatan & daftar tugasnya (dari workbook checklist).
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['department_id', 'name']);
        });

        // Akses aplikasi. Tanpa password/PIN: login selalu diverifikasi Sistem Absensi. Satu pegawai = satu akun.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->restrictOnDelete();
            $table->string('role', 20);
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete(); // jabatan untuk checklist harian
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        // Departemen yang boleh dilihat & direview HOD (diatur Administrator, bukan dari data absensi).
        Schema::create('department_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'department_id']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('department_user');
        Schema::dropIfExists('users');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('departments');
    }
};
