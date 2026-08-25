<?php

namespace App\Modules\Assessment;

use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Modules\Calculation\KpiCalculationEngine;

/**
 * Agregasi KPI tim → KPI Supervisor.
 * Feed:
 *  - SUP-01 Pencapaian target tim = rata-rata final_score KPI anggota tim
 *  - SUP-02 Kualitas kerja tim     = rata-rata achievement seluruh item anggota tim
 *  - SUP-03 Kedisiplinan tim       = rata-rata rate kehadiran anggota tim
 */
class TeamAggregationKpiSyncService
{
    public function __construct(
        protected KpiCalculationEngine $calculationEngine,
        protected AttendanceKpiSyncService $attendanceSync
    ) {}

    public function syncPeriodTeamAggregation(KpiPeriod $period): array
    {
        $spvKpis = EmployeeKpi::with(['employee.position'])
            ->where('period_id', $period->id)
            ->whereHas('employee.position', fn($q) => $q->where('code', 'POS-SPV'))
            ->get();

        $updatedItems = 0;
        $updatedEmployees = 0;

        foreach ($spvKpis as $spvKpi) {
            $spv = $spvKpi->employee;
            if (!$spv) continue;

            $teamKpis = EmployeeKpi::with(['employee', 'items'])
                ->where('period_id', $period->id)
                ->whereHas('employee', fn($q) => $q->where('supervisor_id', $spv->id))
                ->get();

            if ($teamKpis->isEmpty()) continue;

            $changed = false;

            // SUP-01: rata-rata final score tim
            $scores = $teamKpis->filter(fn($k) => $k->final_score !== null)->pluck('final_score');
            if ($scores->isNotEmpty()) {
                $avgScore = round($scores->avg(), 2);
                if ($this->setItemActual($spvKpi, 'SUP-01', $avgScore)) {
                    $updatedItems++;
                    $changed = true;
                }
            }

            // SUP-02: rata-rata achievement seluruh item tim (proxy kualitas)
            $achievements = $teamKpis
                ->flatMap(fn($k) => $k->items->pluck('achievement_percentage'))
                ->filter(fn($v) => $v !== null);
            if ($achievements->isNotEmpty()) {
                $avgAchievement = round($achievements->avg(), 2);
                if ($this->setItemActual($spvKpi, 'SUP-02', $avgAchievement)) {
                    $updatedItems++;
                    $changed = true;
                }
            }

            // SUP-03: rata-rata kehadiran tim
            $rates = [];
            foreach ($teamKpis as $teamKpi) {
                $rate = $this->attendanceSync->calculateAttendanceRate($teamKpi->employee, $period);
                if ($rate !== null) $rates[] = $rate;
            }
            if (!empty($rates)) {
                $avgAttendance = round(array_sum($rates) / count($rates), 2);
                if ($this->setItemActual($spvKpi, 'SUP-03', $avgAttendance)) {
                    $updatedItems++;
                    $changed = true;
                }
            }

            if ($changed) {
                $spvKpi->calculateProgress();
                $updatedEmployees++;
            }
        }

        return [
            'success' => true,
            'message' => "Agregasi tim disinkronkan: {$updatedItems} indikator supervisor pada {$updatedEmployees} karyawan.",
            'updated_items' => $updatedItems,
            'updated_employees' => $updatedEmployees,
        ];
    }

    protected function setItemActual(EmployeeKpi $kpi, string $code, float $value): bool
    {
        $item = $kpi->items->firstWhere('definition_code_snapshot', $code);
        if (!$item) return false;

        $item->actual_decimal = $value;
        $item->status = 'draft';
        $item->save();
        $this->calculationEngine->calculateItem($item);

        return true;
    }
}
