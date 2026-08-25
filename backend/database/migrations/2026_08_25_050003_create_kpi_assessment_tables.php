<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100); // e.g. "Periode Agustus 2026"
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month'); // 1-12
            $table->date('start_date');
            $table->date('end_date');
            $table->dateTime('submission_deadline');
            $table->dateTime('review_deadline');
            $table->dateTime('approval_deadline');
            $table->string('status', 30)->default('DRAFT'); 
            // DRAFT, READY, OPEN, SUBMISSION_CLOSED, IN_REVIEW, WAITING_APPROVAL, PUBLISHED, LOCKED, CANCELLED
            $table->unsignedInteger('total_eligible_employees')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month']);
        });

        Schema::create('kpi_period_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('kpi_periods')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['period_id', 'branch_id']);
        });

        Schema::create('employee_kpis', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('period_id')->constrained('kpi_periods')->restrictOnDelete();
            $table->foreignUlid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('template_version_id')->constrained('kpi_template_versions')->restrictOnDelete();
            $table->ulid('supervisor_id_snapshot')->nullable();
            $table->foreign('supervisor_id_snapshot')->references('id')->on('employees')->nullOnDelete();
            $table->ulid('manager_id_snapshot')->nullable();
            $table->foreign('manager_id_snapshot')->references('id')->on('employees')->nullOnDelete();
            
            $table->string('status', 30)->default('draft');
            // draft, submitted, under_review, revision_required, verified, pending_approval, approved, locked
            
            $table->decimal('progress_percentage', 5, 2)->default(0.00);
            $table->decimal('final_score', 8, 2)->nullable();
            $table->string('rating_code', 50)->nullable();
            $table->string('rating_label', 100)->nullable();
            $table->unsignedInteger('revision_number')->default(0);
            $table->unsignedInteger('row_version')->default(1); // optimistic locking
            
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'employee_id']);
            $table->index(['period_id', 'status']);
            $table->index(['supervisor_id_snapshot', 'status']);
        });

        Schema::create('employee_kpi_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('employee_kpi_id')->constrained('employee_kpis')->cascadeOnDelete();
            $table->foreignId('kpi_definition_id')->nullable()->constrained('kpi_definitions')->nullOnDelete();
            
            // Snapshots immutable
            $table->string('definition_code_snapshot', 50);
            $table->string('name_snapshot', 150);
            $table->decimal('weight_snapshot', 5, 2);
            $table->decimal('target_value_snapshot', 12, 2)->nullable();
            $table->string('target_unit_snapshot', 30)->default('%');
            $table->json('target_json_snapshot')->nullable();
            $table->string('formula_key_snapshot', 50);
            $table->json('formula_params_snapshot')->nullable();
            $table->string('source_type_snapshot', 50);
            $table->boolean('evidence_req_snapshot')->default(false);
            $table->json('rubric_snapshot')->nullable();

            // Status & Actuals
            $table->string('status', 30)->default('not_started');
            // not_started, draft, submitted, under_review, revision_required, verified, assessed, locked
            $table->decimal('actual_decimal', 12, 2)->nullable();
            $table->json('actual_json')->nullable();
            $table->decimal('achievement_percentage', 8, 2)->nullable();
            $table->decimal('weighted_score', 8, 2)->nullable();
            $table->string('calculation_status', 30)->default('pending'); // pending, calculated, unscorable
            $table->text('calculation_note')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->index(['employee_kpi_id', 'status']);
        });

        Schema::create('kpi_actual_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('employee_kpi_item_id')->constrained('employee_kpi_items')->cascadeOnDelete();
            $table->foreignId('input_by')->constrained('users')->cascadeOnDelete();
            $table->decimal('actual_value', 12, 2)->nullable();
            $table->json('actual_json')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('kpi_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('employee_kpi_item_id')->constrained('employee_kpi_items')->cascadeOnDelete();
            $table->string('file_path', 255);
            $table->string('file_name', 255);
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type', 100);
            $table->string('sha256_hash', 64);
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('kpi_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('employee_kpi_id')->constrained('employee_kpis')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('in_progress'); // in_progress, verified, revision_requested
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('kpi_review_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_review_id')->constrained('kpi_reviews')->cascadeOnDelete();
            $table->foreignUlid('employee_kpi_item_id')->constrained('employee_kpi_items')->cascadeOnDelete();
            $table->string('decision', 30); // valid, revision_required
            $table->text('supervisor_note')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('kpi_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('employee_kpi_item_id')->constrained('employee_kpi_items')->cascadeOnDelete();
            $table->foreignId('kpi_review_id')->nullable()->constrained('kpi_reviews')->cascadeOnDelete();
            $table->foreignId('assessed_by')->constrained('users')->cascadeOnDelete();
            $table->decimal('score_points', 6, 2)->default(0);
            $table->decimal('total_points', 6, 2)->default(0);
            $table->decimal('calculated_achievement', 8, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('kpi_assessment_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_assessment_id')->constrained('kpi_assessments')->cascadeOnDelete();
            $table->unsignedBigInteger('criterion_id')->nullable();
            $table->string('criterion_text', 255);
            $table->boolean('is_fulfilled')->default(false);
            $table->decimal('points_earned', 5, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('kpi_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('employee_kpi_id')->constrained('employee_kpis')->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 30); // approved, returned
            $table->text('reason')->nullable();
            $table->unsignedInteger('row_version_snapshot');
            $table->timestamps();
        });

        Schema::create('kpi_correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('employee_kpi_id')->constrained('employee_kpis')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->json('before_json');
            $table->json('after_json');
            $table->string('status', 30)->default('pending'); // pending, approved, rejected, applied
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kpi_calculation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('employee_kpi_id')->constrained('employee_kpis')->cascadeOnDelete();
            $table->string('run_type', 30)->default('submission'); // submission, review, approval, correction, recalculation
            $table->json('input_snapshot');
            $table->json('output_snapshot');
            $table->decimal('total_score', 8, 2);
            $table->string('rating_code', 50)->nullable();
            $table->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('calculated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_calculation_runs');
        Schema::dropIfExists('kpi_correction_requests');
        Schema::dropIfExists('kpi_approvals');
        Schema::dropIfExists('kpi_assessment_answers');
        Schema::dropIfExists('kpi_assessments');
        Schema::dropIfExists('kpi_review_items');
        Schema::dropIfExists('kpi_reviews');
        Schema::dropIfExists('kpi_evidences');
        Schema::dropIfExists('kpi_actual_entries');
        Schema::dropIfExists('employee_kpi_items');
        Schema::dropIfExists('employee_kpis');
        Schema::dropIfExists('kpi_period_branches');
        Schema::dropIfExists('kpi_periods');
    }
};
