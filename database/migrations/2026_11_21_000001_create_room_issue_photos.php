<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Foto bukti pekerjaan di kamar, menempel pada catatan status (Done / Ongoing / Pending). Berkas ada di disk privat.
        Schema::create('room_issue_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_issue_update_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('size')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_issue_photos');
    }
};
