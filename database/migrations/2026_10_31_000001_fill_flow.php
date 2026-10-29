<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu checklist per staff + jabatan + tanggal (isi ulang = memperbarui). Indeks baru dibuat dulu karena FK employee_id memakainya.
        Schema::table('checklists', function (Blueprint $table) {
            $table->unique(['employee_id', 'position_id', 'date'], 'checklists_employee_position_date_unique');
        });
        Schema::table('checklists', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'date']);
        });

        // Status jadi dua: saved (belum direview) dan reviewed. Draft kosong dari alur lama dibuang; sisanya dianggap tersimpan.
        DB::statement("DELETE c FROM checklists c WHERE c.status = 'draft' AND NOT EXISTS (SELECT 1 FROM checklist_items i WHERE i.checklist_id = c.id AND i.status IS NOT NULL)");
        DB::statement("UPDATE checklists SET status = CASE WHEN status = 'approved' THEN 'reviewed' ELSE 'saved' END");

        Schema::table('checklists', function (Blueprint $table) {
            $table->string('status', 20)->default('saved')->change();
            $table->string('checked_by')->nullable()->after('note'); // "Dicek oleh": nama HOD / atasan yang mengecek
            $table->foreignId('filled_by')->nullable()->after('checked_by')->constrained('users')->nullOnDelete(); // akun yang terakhir menyimpan
            $table->dropColumn(['submitted_at', 'review_note']);
        });

        // Pekerjaan di luar daftar tugas (mis. membantu banquet). Tidak dihitung dalam skor.
        Schema::create('checklist_extra_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained()->cascadeOnDelete();
            $table->string('title', 500);
            $table->string('status', 20)->default('done');
            $table->text('note')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Tugas khusus per pegawai Absensi (bukan per akun): HOD memilih untuk staff yang tidak punya akun juga.
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('custom_tasks')->default(false)->after('is_active');
        });
        Schema::create('employee_task', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->primary(['employee_id', 'task_id']);
        });
        DB::statement('INSERT INTO employee_task (employee_id, task_id) SELECT u.employee_id, tu.task_id FROM task_user tu JOIN users u ON u.id = tu.user_id');
        DB::statement('UPDATE employees e JOIN users u ON u.employee_id = e.id SET e.custom_tasks = u.custom_tasks');
        Schema::dropIfExists('task_user');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('custom_tasks');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('custom_tasks')->default(false)->after('position_id');
        });
        Schema::create('task_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'task_id']);
        });
        Schema::dropIfExists('employee_task');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('custom_tasks');
        });
        Schema::dropIfExists('checklist_extra_tasks');
        Schema::table('checklists', function (Blueprint $table) {
            $table->dropConstrainedForeignId('filled_by');
            $table->dropColumn('checked_by');
            $table->timestamp('submitted_at')->nullable();
            $table->text('review_note')->nullable();
            $table->string('status', 20)->default('draft')->change();
            $table->unique(['employee_id', 'date']);
        });
        Schema::table('checklists', function (Blueprint $table) {
            $table->dropUnique('checklists_employee_position_date_unique');
        });
    }
};
