<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dokumen di obrolan (PDF, Word, Excel, ...) dan foto grup.
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('file_path')->nullable()->after('photo_path');
            $table->string('file_name', 191)->nullable()->after('file_path');
            $table->unsignedInteger('file_size')->nullable()->after('file_name');
            $table->string('file_mime', 100)->nullable()->after('file_size');
        });
        Schema::table('chat_threads', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'file_name', 'file_size', 'file_mime']);
        });
        Schema::table('chat_threads', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
