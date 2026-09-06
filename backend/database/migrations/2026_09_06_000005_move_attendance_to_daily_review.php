<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ATTENDANCE_CODES = ['ADM-05', 'KSR-06', 'GUD-07', 'CS-06'];

    public function up(): void
    {
        DB::table('kpi_definitions')
            ->whereIn('code', self::ATTENDANCE_CODES)
            ->update(['source_type' => 'supervisor']);

        DB::table('kpi_template_items')
            ->whereIn('kpi_definition_id', DB::table('kpi_definitions')->whereIn('code', self::ATTENDANCE_CODES)->pluck('id'))
            ->update(['source_type' => 'supervisor']);

        $options = [
            ['code' => 'FAIR', 'label' => 'Cukup', 'score' => 75.00],
            ['code' => 'GOOD', 'label' => 'Baik', 'score' => 85.00],
            ['code' => 'VERY_GOOD', 'label' => 'Sangat Baik', 'score' => 95.00],
        ];

        DB::table('employee_kpi_items')
            ->where('source_type_snapshot', 'supervisor')
            ->orderBy('id')
            ->chunkById(100, function ($items) use ($options): void {
                foreach ($items as $item) {
                    $snapshot = is_string($item->rubric_snapshot)
                        ? json_decode($item->rubric_snapshot, true)
                        : $item->rubric_snapshot;
                    $snapshot = is_array($snapshot) ? $snapshot : [];
                    $snapshot['manual_rating_options'] ??= $options;

                    DB::table('employee_kpi_items')
                        ->where('id', $item->id)
                        ->update(['rubric_snapshot' => json_encode($snapshot)]);
                }
            });
    }

    public function down(): void
    {
        DB::table('kpi_definitions')
            ->whereIn('code', self::ATTENDANCE_CODES)
            ->update(['source_type' => 'system']);

        DB::table('kpi_template_items')
            ->whereIn('kpi_definition_id', DB::table('kpi_definitions')->whereIn('code', self::ATTENDANCE_CODES)->pluck('id'))
            ->update(['source_type' => 'system']);
    }
};
