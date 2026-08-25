<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('admin_work_logs');

        // Subsistem Admin Work-Log — catatan fakta kerja harian Admin
        // Feed KPI: ADM-01 (akurasi input), ADM-03 (kelengkapan dokumen), ADM-04 (rekonsiliasi)
        Schema::create('admin_work_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('kpi_periods')->nullOnDelete();
            $table->date('work_date');

            // ADM-01: akurasi input data
            $table->integer('records_input')->default(0);
            $table->integer('records_corrected')->default(0);

            // ADM-03: kelengkapan dokumen
            $table->integer('documents_eligible')->default(0);
            $table->integer('documents_complete')->default(0);

            // ADM-04: rekonsiliasi data
            $table->integer('reconciliations_total')->default(0);
            $table->integer('reconciliations_success')->default(0);

            $table->string('notes', 500)->nullable();
            $table->string('evidence_path', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['work_date', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_work_logs');
    }
};
