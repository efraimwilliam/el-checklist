<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pengingat Telegram untuk yang sudah diambil tapi belum selesai: 0 = belum, 1 = ke pengambil, 2 = ke HOD.
        foreach (['guest_requests', 'room_issues'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedTinyInteger('claimed_reminders')->default(0));
        }
    }

    public function down(): void
    {
        foreach (['guest_requests', 'room_issues'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('claimed_reminders'));
        }
    }
};
