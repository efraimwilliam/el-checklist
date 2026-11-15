<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Permintaan tamu yang dicatat dari obrolan (kartu di pesan).
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('guest_request_id')->nullable()->after('chronology_report_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guest_request_id');
        });
    }
};
