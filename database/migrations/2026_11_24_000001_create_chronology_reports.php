<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chronology Report: laporan naratif kejadian dengan tamu (ditulis staf, mis. GRO, dalam bahasa Inggris untuk manajemen).
        Schema::create('chronology_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nationality', 80)->nullable();
            $table->date('check_in');
            $table->date('check_out')->nullable();
            $table->string('booking_source', 120)->nullable();
            $table->foreignId('room_issue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft, final
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['author_id', 'created_at']);
            $table->index(['department_id', 'created_at']);
        });

        $table = 'chronology_report_guests';
        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->foreignId('chronology_report_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('name', 120);
            $table->string('room_number', 30)->nullable(); // teks bebas: tamu bisa di villa / kamar di luar data kamar
            $table->timestamps();
        });

        Schema::create('chronology_report_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chronology_report_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->dateTime('occurred_at')->nullable();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chronology_report_entries');
        Schema::dropIfExists('chronology_report_guests');
        Schema::dropIfExists('chronology_reports');
    }
};
