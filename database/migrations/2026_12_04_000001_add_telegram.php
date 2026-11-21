<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Akun Telegram yang ditautkan ke pengguna (satu akun Telegram = satu pengguna).
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_id')->nullable()->unique()->after('employee_id');
        });

        // Pesan kartu yang dikirim bot (permintaan / kendala) supaya bisa diperbarui saat statusnya berubah dan dikenali saat dibalas.
        Schema::create('telegram_cards', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id');
            $table->unsignedBigInteger('message_id');
            $table->boolean('origin')->default(false); // true = pesan asli pengguna (bukan kartu bot): hanya untuk dikenali saat dibalas
            $table->foreignId('guest_request_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('room_issue_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['chat_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_cards');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('telegram_id'));
    }
};
