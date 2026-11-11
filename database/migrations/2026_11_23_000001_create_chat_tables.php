<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Obrolan pekerjaan ala WhatsApp: chat pribadi (direct_key = "idKecil-idBesar"), grup (subject), atau menempel pada satu kendala / checklist.
        Schema::create('chat_threads', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 120)->nullable();
            $table->string('direct_key', 40)->nullable()->unique();
            $table->foreignId('room_issue_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('checklist_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('chat_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamps();
            $table->unique(['chat_thread_id', 'user_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10)->default('text'); // text | system
            $table->text('body')->nullable();
            $table->string('photo_path')->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['chat_thread_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_participants');
        Schema::dropIfExists('chat_threads');
    }
};
