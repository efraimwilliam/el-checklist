<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Asal permintaan: form (dialog / menu lampiran) atau ketik bebas di obrolan (catatannya = teks pesan itu).
        Schema::table('guest_requests', function (Blueprint $table) {
            $table->string('source', 10)->default('form')->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('guest_requests', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
