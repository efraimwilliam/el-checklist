<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 0 = belum diingatkan, 1 = pengingat 15 menit terkirim, 2 = pengingat 30 menit terkirim (Telegram).
        Schema::table('guest_requests', fn (Blueprint $table) => $table->unsignedTinyInteger('escalated')->default(0));
    }

    public function down(): void
    {
        Schema::table('guest_requests', fn (Blueprint $table) => $table->dropColumn('escalated'));
    }
};
