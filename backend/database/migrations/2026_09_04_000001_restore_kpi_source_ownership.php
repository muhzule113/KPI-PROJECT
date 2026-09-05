<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 110001 pernah meratakan semua producer menjadi "system". Pulihkan
        // producer hanya berdasarkan kode indikator; snapshot final tidak disentuh.
        $sources = [
            'TEK-01' => 'employee', 'TEK-02' => 'employee', 'TEK-03' => 'cross_role', 'TEK-04' => 'employee',
            'TEK-05' => 'supervisor', 'TEK-06' => 'supervisor', 'TEK-07' => 'system',
            'CS-01' => 'employee', 'CS-02' => 'employee', 'CS-03' => 'employee', 'CS-04' => 'employee',
            'CS-05' => 'cross_role', 'CS-06' => 'system',
            'ADM-01' => 'employee', 'ADM-02' => 'system', 'ADM-03' => 'employee', 'ADM-04' => 'employee',
            'ADM-05' => 'system', 'ADM-06' => 'supervisor',
            'KSR-01' => 'import', 'KSR-02' => 'import', 'KSR-03' => 'import', 'KSR-04' => 'import',
            'KSR-05' => 'supervisor', 'KSR-06' => 'system',
            'GUD-01' => 'system', 'GUD-02' => 'system', 'GUD-03' => 'system', 'GUD-04' => 'system',
            'GUD-05' => 'system', 'GUD-06' => 'supervisor', 'GUD-07' => 'system',
            'SUP-01' => 'system', 'SUP-02' => 'system', 'SUP-03' => 'system', 'SUP-04' => 'system',
            'SUP-05' => 'employee', 'SUP-06' => 'supervisor', 'SUP-07' => 'system',
        ];

        foreach ($sources as $code => $source) {
            $definitionIds = DB::table('kpi_definitions')->where('code', $code)->pluck('id');
            if ($definitionIds->isEmpty()) {
                continue;
            }

            DB::table('kpi_definitions')
                ->whereIn('id', $definitionIds)
                ->update(['source_type' => $source]);
            DB::table('kpi_template_items')
                ->whereIn('kpi_definition_id', $definitionIds)
                ->update(['source_type' => $source]);

            $employeeItemIds = DB::table('employee_kpi_items')
                ->whereIn('kpi_definition_id', $definitionIds)
                ->where('status', '!=', 'locked')
                ->pluck('id');
            DB::table('employee_kpi_items')
                ->whereIn('id', $employeeItemIds)
                ->update(['source_type_snapshot' => $source]);

            if ($source === 'employee' && $employeeItemIds->isNotEmpty()) {
                DB::table('kpi_daily_entries')
                    ->whereIn('employee_kpi_item_id', $employeeItemIds)
                    ->whereNull('employee_submitted_at')
                    ->update(['entry_status' => 'draft']);
            }
        }
    }

    public function down(): void
    {
        // Source ownership is part of the active contract; it is not reverted.
    }
};
