<?php

use App\Push\VapidKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Perangkat yang menyalakan notifikasi obrolan. Satu endpoint = satu perangkat / browser; hanya terikat ke satu pengguna
        // (pengguna lain yang menyalakan di perangkat yang sama mengambil alih, jadi HP bersama tidak menerima pesan pengguna lama).
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        // Kunci VAPID dibuat di sini sekali (saat deploy) supaya tidak berebut dibuat oleh permintaan pertama.
        try {
            VapidKeys::pair();
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
