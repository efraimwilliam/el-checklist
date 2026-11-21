<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_cards', fn (Blueprint $table) => $table->foreignId('lost_item_id')->nullable()->constrained()->cascadeOnDelete());
    }

    public function down(): void
    {
        Schema::table('telegram_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lost_item_id');
        });
    }
};
