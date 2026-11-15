<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nama barang untuk jenis "Lainnya" (mis. "lan cable" dari "102 req lan cable").
        Schema::table('guest_requests', function (Blueprint $table) {
            $table->string('item_name', 80)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('guest_requests', function (Blueprint $table) {
            $table->dropColumn('item_name');
        });
    }
};
