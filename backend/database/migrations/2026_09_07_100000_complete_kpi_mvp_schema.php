<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_placements', function (Blueprint $table): void {
            $table->index(['employee_id', 'effective_from', 'effective_until'], 'placements_effective_index');
        });

        DB::table('employees')->orderBy('id')->each(function (object $employee): void {
            if (! DB::table('employee_placements')->where('employee_id', $employee->id)->exists()) {
                DB::table('employee_placements')->insert([
                    'employee_id' => $employee->id,
                    'position_id' => $employee->position_id,
                    'branch_id' => $employee->branch_id,
                    'supervisor_id' => $employee->supervisor_id,
                    'effective_from' => $employee->joined_at,
                    'effective_until' => $employee->ended_at,
                    'notes' => 'Backfill placement awal',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('employee_kpis', function (Blueprint $table): void {
            $table->string('employee_number_snapshot', 50)->nullable()->after('employee_id');
            $table->string('employee_name_snapshot', 150)->nullable()->after('employee_number_snapshot');
            $table->unsignedBigInteger('placement_id_snapshot')->nullable()->after('manager_id_snapshot')->index();
            $table->string('eligibility', 20)->default('full')->after('position_code_snapshot')->index();
            $table->decimal('score_cap_snapshot', 9, 6)->default(100)->after('eligibility');
            $table->json('rating_bands_snapshot')->nullable()->after('score_cap_snapshot');
        });

        DB::table('employee_kpis')->orderBy('id')->each(function (object $kpi): void {
            $employee = DB::table('employees')->where('id', $kpi->employee_id)->first();
            if (! $employee) {
                return;
            }
            DB::table('employee_kpis')->where('id', $kpi->id)->update([
                'employee_number_snapshot' => $employee->employee_number,
                'employee_name_snapshot' => $employee->name,
            ]);
        });

        Schema::table('employee_kpi_items', function (Blueprint $table): void {
            $table->decimal('actual_decimal', 18, 6)->nullable()->change();
            $table->decimal('achievement_percentage', 12, 6)->nullable()->change();
            $table->decimal('weighted_score', 12, 6)->nullable()->change();
        });

        Schema::create('report_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('period_id')->constrained('kpi_periods')->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('report_type', 40);
            $table->date('report_date');
            $table->dateTime('deadline_at');
            $table->dateTime('submitted_at')->nullable();
            $table->boolean('is_on_time')->nullable();
            $table->string('status', 30)->default('scheduled');
            $table->string('source', 50)->default('system');
            $table->json('content_snapshot')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['period_id', 'employee_id', 'report_type', 'report_date'], 'report_submission_unique');
            $table->index(['period_id', 'status', 'deadline_at']);
        });

        Schema::table('import_batches', function (Blueprint $table): void {
            $table->string('file_hash_sha256', 64)->nullable()->change();
            $table->foreignId('branch_id')->nullable()->after('period_id')->constrained('branches')->restrictOnDelete();
            $table->char('currency', 3)->default('IDR')->after('branch_id');
            $table->string('scan_status', 20)->default('quarantine')->after('status');
            $table->timestamp('scanned_at')->nullable()->after('scan_status');
            $table->text('scan_note')->nullable()->after('scanned_at');
            $table->foreignUlid('superseded_by_id')->nullable()->after('scan_note')->constrained('import_batches')->nullOnDelete();
        });
        DB::table('import_batches')->select('source_application', 'file_hash_sha256')
            ->whereNotNull('file_hash_sha256')->groupBy('source_application', 'file_hash_sha256')->havingRaw('COUNT(*) > 1')
            ->get()->each(function (object $duplicate): void {
                $ids = DB::table('import_batches')->where('source_application', $duplicate->source_application)
                    ->where('file_hash_sha256', $duplicate->file_hash_sha256)->orderBy('created_at')->pluck('id');
                $keep = $ids->shift();
                DB::table('import_batches')->whereIn('id', $ids)->update([
                    'status' => 'superseded', 'file_hash_sha256' => null, 'superseded_by_id' => $keep,
                ]);
            });
        Schema::table('import_batches', fn (Blueprint $table) => $table->unique(
            ['source_application', 'file_hash_sha256'], 'import_batches_source_hash_unique'
        ));

        Schema::create('device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('platform', 20);
            $table->string('device_id', 191);
            $table->string('device_name', 191)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_id', 'platform'], 'device_tokens_device_unique');
        });

        Schema::table('system_notifications', function (Blueprint $table): void {
            $table->string('dedupe_key', 64)->nullable()->unique()->after('action_url');
        });

        Schema::table('kpi_rating_schemes', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('name');
            $table->decimal('score_cap', 9, 6)->default(100)->after('version');
            $table->boolean('is_active')->default(true)->after('is_default');
            $table->unique(['name', 'version'], 'rating_schemes_name_version_unique');
        });

        DB::table('kpi_template_items')->whereIn('kpi_definition_id', DB::table('kpi_definitions')->where('code', 'CS-05')->select('id'))
            ->update(['target_unit' => 'komplain']);
        DB::table('employee_kpi_items')->where('definition_code_snapshot', 'CS-05')
            ->whereIn('employee_kpi_id', DB::table('employee_kpis')->whereNotIn('status', ['locked'])->select('id'))
            ->update(['target_unit_snapshot' => 'komplain']);
    }

    public function down(): void
    {
        Schema::table('kpi_rating_schemes', function (Blueprint $table): void {
            $table->dropUnique('rating_schemes_name_version_unique');
            $table->dropColumn(['version', 'score_cap', 'is_active']);
        });
        Schema::table('system_notifications', fn (Blueprint $table) => $table->dropColumn('dedupe_key'));
        Schema::dropIfExists('device_tokens');
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropUnique('import_batches_source_hash_unique');
            $table->dropConstrainedForeignId('superseded_by_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['currency', 'scan_status', 'scanned_at', 'scan_note']);
        });
        Schema::dropIfExists('report_submissions');
        Schema::table('employee_kpis', fn (Blueprint $table) => $table->dropColumn([
            'employee_number_snapshot', 'employee_name_snapshot', 'placement_id_snapshot', 'eligibility',
            'score_cap_snapshot', 'rating_bands_snapshot',
        ]));
        Schema::table('employee_placements', fn (Blueprint $table) => $table->dropIndex('placements_effective_index'));
    }
};
