<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['period_from', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_notes');
    }
};
