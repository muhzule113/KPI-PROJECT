<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_daily_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('employee_kpi_item_id')->constrained('employee_kpi_items')->cascadeOnDelete();
            $table->date('entry_date');

            $table->decimal('employee_actual_decimal', 12, 2)->nullable();
            $table->json('employee_actual_json')->nullable();
            $table->text('employee_note')->nullable();
            $table->foreignId('employee_entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entry_status', 30)->default('submitted'); // system-created/review-ready
            $table->timestamp('employee_submitted_at')->nullable();

            $table->decimal('supervisor_actual_decimal', 12, 2)->nullable();
            $table->json('supervisor_actual_json')->nullable();
            $table->json('supervisor_answers_json')->nullable();
            $table->decimal('supervisor_score_percentage', 8, 2)->nullable();
            $table->text('supervisor_note')->nullable();
            $table->foreignId('supervisor_assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('supervisor_status', 30)->default('pending'); // pending, approved, revision_required
            $table->timestamp('supervisor_assessed_at')->nullable();

            $table->decimal('manager_actual_decimal', 12, 2)->nullable();
            $table->json('manager_actual_json')->nullable();
            $table->json('manager_answers_json')->nullable();
            $table->decimal('manager_score_percentage', 8, 2)->nullable();
            $table->text('manager_note')->nullable();
            $table->foreignId('manager_assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('manager_status', 30)->default('pending'); // pending, approved, revision_required
            $table->timestamp('manager_assessed_at')->nullable();

            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->unique(['employee_kpi_item_id', 'entry_date']);
            $table->index(['entry_date', 'entry_status']);
            $table->index(['entry_date', 'supervisor_status']);
            $table->index(['entry_date', 'manager_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_daily_entries');
    }
};
