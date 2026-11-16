<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Token notifikasi (Firebase Cloud Messaging) aplikasi Android menempel pada token login perangkatnya:
// keluar / token kedaluwarsa = notifikasi ke perangkat itu ikut berhenti.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('fcm_token', 512)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('fcm_token');
        });
    }
};
