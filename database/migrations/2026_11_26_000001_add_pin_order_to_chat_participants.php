<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Obrolan yang disematkan pengguna (per pengguna): pin_order 1..n, kosong = tidak disematkan.
        Schema::table('chat_participants', function (Blueprint $table) {
            $table->unsignedSmallInteger('pin_order')->nullable()->after('last_read_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_participants', function (Blueprint $table) {
            $table->dropColumn('pin_order');
        });
    }
};
