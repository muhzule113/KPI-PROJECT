<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_evidences', function (Blueprint $table) {
            $table->string('scan_status', 20)->default('pending')->after('sha256_hash');
            $table->timestamp('scanned_at')->nullable()->after('scan_status');
            $table->text('scan_note')->nullable()->after('scanned_at');
        });

        Schema::table('kpi_correction_requests', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('status');
            $table->unsignedInteger('row_version_snapshot')->nullable()->after('rejection_reason');
        });

    }

    public function down(): void
    {
        Schema::table('kpi_correction_requests', function (Blueprint $table) {
            $table->dropColumn(['rejection_reason', 'row_version_snapshot']);
        });

        Schema::table('kpi_evidences', function (Blueprint $table) {
            $table->dropColumn(['scan_status', 'scanned_at', 'scan_note']);
        });
    }
};
