<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('metric_type', 50)->default('percentage'); // percentage, count, currency, rubric, time
            $table->string('unit', 30)->default('%');
            $table->string('direction', 30)->default('higher'); // higher, lower, zero_tolerance
            $table->string('default_formula', 50)->default('higher_is_better');
            $table->string('source_type', 50)->default('employee'); // employee, supervisor, cross_role, import, system
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('kpi_rating_schemes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('kpi_rating_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rating_scheme_id')->constrained('kpi_rating_schemes')->cascadeOnDelete();
            $table->string('code', 50); // STAR, VERY_GOOD, GOOD, FAIR, POOR
            $table->string('label', 100); // Istimewa, Sangat Baik, etc.
            $table->decimal('min_score', 8, 2);
            $table->decimal('max_score', 8, 2);
            $table->string('color', 30)->default('#10B981'); // Hex color
            $table->string('badge_icon', 50)->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
        });

        Schema::create('kpi_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->foreignId('position_id')->constrained('positions')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('kpi_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_template_id')->constrained('kpi_templates')->cascadeOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->string('status', 30)->default('draft'); // draft, active, retired
            $table->decimal('total_weight', 8, 2)->default(0.00);
            $table->foreignId('rating_scheme_id')->nullable()->constrained('kpi_rating_schemes')->nullOnDelete();
            $table->string('checksum', 64)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['kpi_template_id', 'version_number']);
        });

        Schema::create('kpi_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_version_id')->constrained('kpi_template_versions')->cascadeOnDelete();
            $table->foreignId('kpi_definition_id')->constrained('kpi_definitions')->restrictOnDelete();
            $table->decimal('weight', 5, 2); // e.g. 25.00
            $table->decimal('target_value', 12, 2)->nullable();
            $table->string('target_unit', 30)->default('%');
            $table->json('target_json')->nullable(); // Extended parameters (e.g. failure_limit, full_score_limit)
            $table->string('formula_key', 50)->default('higher_is_better'); // higher_is_better, lower_is_better, zero_tolerance, rubric
            $table->json('formula_params')->nullable();
            $table->string('source_type', 50)->default('employee');
            $table->boolean('evidence_required')->default(false);
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
        });

        Schema::create('kpi_rubrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_item_id')->constrained('kpi_template_items')->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('kpi_rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_id')->constrained('kpi_rubrics')->cascadeOnDelete();
            $table->string('criterion_text', 255);
            $table->decimal('points', 5, 2)->default(1.00);
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_rubric_criteria');
        Schema::dropIfExists('kpi_rubrics');
        Schema::dropIfExists('kpi_template_items');
        Schema::dropIfExists('kpi_template_versions');
        Schema::dropIfExists('kpi_templates');
        Schema::dropIfExists('kpi_rating_bands');
        Schema::dropIfExists('kpi_rating_schemes');
        Schema::dropIfExists('kpi_definitions');
    }
};
