<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Permintaan tamu (air mineral, handuk, laundry, MUR, dst.): satu baris = satu jenis permintaan di satu kamar.
        Schema::create('guest_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->string('note', 500)->nullable();
            $table->string('priority', 20)->default('biasa'); // biasa, mendesak
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('status', 20)->default('baru'); // baru, diambil, selesai
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['assigned_department_id', 'status']);
            $table->index(['room_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_requests');
    }
};
