<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Hari ke belakang untuk menganggap kendala yang sama di kamar yang sama sebagai berulang (sama dengan RoomIssue::RECURRENCE_DAYS). */
    private const DAYS = 30;

    public function up(): void
    {
        // 1 = kendala pertama; 2 atau lebih = berulang (kategori sama di kamar sama dalam 30 hari sebelumnya).
        Schema::table('room_issues', function (Blueprint $table) {
            $table->unsignedTinyInteger('recurrence')->default(1)->after('priority');
        });

        // Isi untuk data yang sudah ada, per kamar dan kategori, urut waktu dicatat.
        $previous = [];
        foreach (DB::table('room_issues')->whereNotNull('category_id')->orderBy('created_at')->orderBy('id')->get(['id', 'room_id', 'category_id', 'created_at']) as $row) {
            $key = $row->room_id.'|'.$row->category_id;
            $at = strtotime($row->created_at);
            $recent = array_filter($previous[$key] ?? [], fn (int $time) => $time >= $at - self::DAYS * 86400);
            DB::table('room_issues')->where('id', $row->id)->update(['recurrence' => min(255, count($recent) + 1)]);
            $previous[$key][] = $at;
        }
    }

    public function down(): void
    {
        Schema::table('room_issues', function (Blueprint $table) {
            $table->dropColumn('recurrence');
        });
    }
};
