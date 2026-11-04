<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Selain kamar, kotak di denah bisa mewakili satu gedung / area (peta kawasan): isi `building` (sama dengan rooms.building), room_id kosong.
        Schema::table('floor_plan_areas', function (Blueprint $table) {
            $table->unsignedBigInteger('room_id')->nullable()->change();
            $table->string('building', 60)->nullable()->after('room_id');
            $table->unique(['floor_plan_id', 'building']);
        });
    }

    public function down(): void
    {
        Schema::table('floor_plan_areas', function (Blueprint $table) {
            $table->dropUnique(['floor_plan_id', 'building']);
            $table->dropColumn('building');
        });
    }
};
