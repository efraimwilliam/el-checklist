<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Kata balasan pada kartu tugas di obrolan: ambil (copy, ok, otw, ...) dan selesai (done, sudah, ...); diatur di Kamus ketik bebas > Kata bantu. */
    public function up(): void
    {
        $now = now();
        foreach (['copy', 'copied', 'ok', 'oke', 'okay', 'okey', 'okee', 'otw', 'on the way', 'on it', 'siap', 'noted', 'roger', 'sip', 'gas', 'meluncur', 'ready', 'ambil', 'saya ambil', 'diambil'] as $term) {
            DB::table('quick_terms')->insertOrIgnore(['group' => 'claim', 'target' => null, 'term' => $term, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (['done', 'sudah', 'sdh', 'udah', 'udh', 'selesai', 'beres', 'clear', 'cleared', 'finish', 'finished', 'complete', 'completed', 'sudah dikirim', 'sudah diantar', 'delivered'] as $term) {
            DB::table('quick_terms')->insertOrIgnore(['group' => 'done', 'target' => null, 'term' => $term, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('quick_terms')->whereIn('group', ['claim', 'done'])->delete();
    }
};
