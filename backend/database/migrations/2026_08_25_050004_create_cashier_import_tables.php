<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mapping_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100); // e.g. "Default POS Laporan Kasir"
            $table->string('source_application', 100)->default('POS_SYSTEM');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('import_mapping_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mapping_template_id')->constrained('import_mapping_templates')->cascadeOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->json('mappings_json'); // column headers to db fields mapping
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['mapping_template_id', 'version_number'], 'import_map_ver_unique');
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('file_name', 255);
            $table->string('file_path', 255);
            $table->string('file_hash_sha256', 64);
            $table->foreignId('mapping_version_id')->nullable()->constrained('import_mapping_versions')->nullOnDelete();
            $table->foreignId('period_id')->constrained('kpi_periods')->restrictOnDelete();
            $table->foreignId('uploader_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('uploaded');
            // uploaded, scanning, parsing, ready_for_preview, confirmed, committed, failed, cancelled
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->json('summary_json')->nullable();
            $table->json('issues_json')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['period_id', 'status']);
        });

        Schema::create('cashier_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('kpi_periods')->restrictOnDelete();
            $table->foreignUlid('cashier_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('cashier_name_raw', 150)->nullable();
            $table->string('transaction_number', 100);
            $table->dateTime('transaction_date');
            $table->decimal('transaction_amount', 14, 2)->default(0.00);
            $table->decimal('system_cash_amount', 14, 2)->default(0.00);
            $table->decimal('actual_cash_amount', 14, 2)->default(0.00);
            $table->decimal('cash_difference', 14, 2)->default(0.00);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status', 50)->default('SUCCESS'); // SUCCESS, REFUND, VOID, CANCELLED
            $table->boolean('is_duplicate')->default(false);
            $table->timestamps();

            $table->index(['period_id', 'cashier_employee_id']);
            $table->index(['transaction_number', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_transactions');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('import_mapping_versions');
        Schema::dropIfExists('import_mapping_templates');
    }
};
