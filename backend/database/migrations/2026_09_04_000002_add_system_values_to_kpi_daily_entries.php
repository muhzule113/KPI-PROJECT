<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('kpi_daily_entries', 'system_actual_decimal')) {
            Schema::table('kpi_daily_entries', function (Blueprint $table): void {
                $table->decimal('system_actual_decimal', 12, 6)->nullable()->after('entry_status');
                $table->json('system_actual_json')->nullable()->after('system_actual_decimal');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('kpi_daily_entries', 'system_actual_decimal')) {
            Schema::table('kpi_daily_entries', function (Blueprint $table): void {
                $table->dropColumn(['system_actual_decimal', 'system_actual_json']);
            });
        }
    }
};
