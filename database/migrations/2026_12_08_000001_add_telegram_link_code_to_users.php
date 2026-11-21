<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tautan penghubung Telegram yang dibuat HR (berlaku beberapa hari, sekali pakai); disimpan di basis data supaya tahan `cache:clear`.
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_link_code', 32)->nullable()->unique();
            $table->dateTime('telegram_link_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_link_code', 'telegram_link_expires_at']);
        });
    }
};
