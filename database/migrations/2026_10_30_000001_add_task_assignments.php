<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // custom_tasks = false: checklist memakai semua tugas aktif jabatan (bawaan). true: hanya tugas yang dipilih HOD di task_user.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('custom_tasks')->default(false)->after('position_id');
        });

        Schema::create('task_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_user');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('custom_tasks');
        });
    }
};
