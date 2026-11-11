<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chronology Report yang dikirim ke obrolan (kartu di pesan).
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('chronology_report_id')->nullable()->after('checklist_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chronology_report_id');
        });
    }
};
