<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Denah (gambar) per lantai / gedung. Gambar disimpan di disk privat; PDF diubah menjadi gambar di browser saat unggah.
        Schema::create('floor_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('floor', 30)->nullable();
            $table->string('image_path');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Kotak area satu kamar di atas denah. Posisi dan ukuran dalam persen (0-100) dari lebar / tinggi gambar.
        Schema::create('floor_plan_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('floor_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('x', 6, 3);
            $table->decimal('y', 6, 3);
            $table->decimal('w', 6, 3);
            $table->decimal('h', 6, 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floor_plan_areas');
        Schema::dropIfExists('floor_plans');
    }
};
