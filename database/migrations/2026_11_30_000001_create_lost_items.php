<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Barang tertinggal (lost & found), dicatat dan diperbarui dari obrolan (tanpa menu sendiri).
        Schema::create('lost_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location', 120)->nullable(); // bila bukan di kamar
            $table->string('description', 200);
            $table->string('photo_path')->nullable();
            $table->string('stored_at', 120)->nullable(); // disimpan di mana (mis. kantor HK)
            $table->string('status', 20)->default('disimpan'); // disimpan, diambil, dikirim, dilepas
            $table->string('resolution_note', 300)->nullable(); // nama penerima / resi / alasan
            $table->foreignId('found_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('found_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('source', 10)->default('form'); // form | chat (foto + "tertinggal")
            $table->timestamps();
            $table->index(['status', 'found_at']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('lost_item_id')->nullable()->after('guest_request_id')->constrained()->nullOnDelete();
        });

        // Kata yang menandai barang tertinggal di obrolan (Kamus ketik bebas > Kata bantu).
        $now = now();
        foreach (['tertinggal', 'ketinggalan', 'lost and found', 'lost & found', 'lost item', 'left behind', 'left item', 'barang tamu', 'barang ketinggalan'] as $term) {
            DB::table('quick_terms')->insertOrIgnore(['group' => 'lost', 'target' => null, 'term' => $term, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::table('chat_messages', fn (Blueprint $table) => $table->dropConstrainedForeignId('lost_item_id'));
        Schema::dropIfExists('lost_items');
        DB::table('quick_terms')->where('group', 'lost')->delete();
    }
};
