<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('kpi_definitions')
            ->whereIn('source_type', ['employee', 'cross_role', 'import'])
            ->update(['source_type' => 'system']);

        DB::table('kpi_template_items')
            ->whereIn('source_type', ['employee', 'cross_role', 'import'])
            ->update(['source_type' => 'system']);

        DB::table('employee_kpi_items')
            ->whereIn('source_type_snapshot', ['employee', 'cross_role', 'import'])
            ->update(['source_type_snapshot' => 'system']);

        DB::table('kpi_daily_entries')->update([
            'employee_actual_decimal' => null,
            'employee_actual_json' => null,
            'employee_note' => null,
            'employee_entered_by' => null,
            'employee_submitted_at' => null,
            'entry_status' => 'submitted',
        ]);
    }

    public function down(): void
    {
        // Legacy source types are intentionally not restored: they permit
        // employee-entered KPI actuals, which is no longer a valid contract.
    }
};
