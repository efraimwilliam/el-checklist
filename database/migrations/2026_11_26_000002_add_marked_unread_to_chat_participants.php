<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Tandai belum dibaca" (titik hijau di daftar) walau semua pesan sebenarnya sudah dibuka; hilang saat obrolan dibuka lagi.
        Schema::table('chat_participants', function (Blueprint $table) {
            $table->boolean('marked_unread')->default(false)->after('pin_order');
        });
    }

    public function down(): void
    {
        Schema::table('chat_participants', function (Blueprint $table) {
            $table->dropColumn('marked_unread');
        });
    }
};
