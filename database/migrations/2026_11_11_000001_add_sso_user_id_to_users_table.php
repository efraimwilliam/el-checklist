<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Id akun di Portal SSO; dikunci pada login pertama lewat portal (dicocokkan dari username).
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('sso_user_id')->nullable()->unique()->after('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sso_user_id']);
            $table->dropColumn('sso_user_id');
        });
    }
};
