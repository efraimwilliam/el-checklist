<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catatan "muncul lagi" di kendala lama menunjuk ke kendala baru yang berulang.
        Schema::table('room_issue_updates', function (Blueprint $table) {
            $table->foreignId('related_issue_id')->nullable()->after('kind')->constrained('room_issues')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('room_issue_updates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('related_issue_id');
        });
    }
};
