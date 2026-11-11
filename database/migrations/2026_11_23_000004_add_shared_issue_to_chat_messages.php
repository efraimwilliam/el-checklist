<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kendala yang dikirim ke obrolan (tampil sebagai kartu di pesan).
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('room_issue_id')->nullable()->after('reply_to_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_issue_id');
        });
    }
};
