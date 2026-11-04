<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tindak lanjut satu isian tugas yang Pending: terbuka / ditangani / selesai + catatan penangan.
        Schema::create('issue_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('open');
            $table->text('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_followups');
    }
};
