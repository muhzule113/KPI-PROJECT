<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('coaching_logs');

        // Subsistem Coaching Log — catatan coaching & evaluasi Supervisor
        // Feed KPI: SUP-05 (coaching & evaluasi karyawan)
        Schema::create('coaching_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('supervisor_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('coaching_date');
            $table->string('topic', 150);
            $table->text('notes')->nullable();
            $table->boolean('target_met')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->foreignId('period_id')->nullable()->constrained('kpi_periods')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['supervisor_id', 'coaching_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coaching_logs');
    }
};
