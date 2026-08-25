<?php

namespace App\Modules\Assessment;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Modules\Calculation\KpiCalculationEngine;

/**
 * Subsistem Absensi → KPI Kehadiran & Disiplin.
 * Feed: ADM-05, KSR-06, GUD-07, CS-06 (dan SUP-03 via agregasi).
 *
 * Rate = (hari hadir / hari kerja Senin–Jumat dalam periode) × 100.
 * Status hadir: present, late, permission, sick_leave. Absent = tidak hadir.
 */
class AttendanceKpiSyncService
{
    /** Kode indikator yang sumber datanya absensi */
    public const ATTENDANCE_ITEM_CODES = ['ADM-05', 'KSR-06', 'GUD-07', 'CS-06'];

    public function __construct(
        protected KpiCalculationEngine $calculationEngine
    ) {}

    public function syncPeriodAttendanceData(KpiPeriod $period): array
    {
        $kpis = EmployeeKpi::with(['employee', 'items'])
            ->where('period_id', $period->id)
            ->get();

        $updatedItems = 0;
        $updatedEmployees = 0;

        foreach ($kpis as $kpi) {
            $emp = $kpi->employee;
            if (!$emp) continue;

            $items = $kpi->items->whereIn('definition_code_snapshot', self::ATTENDANCE_ITEM_CODES);
            if ($items->isEmpty()) continue;

            $rate = $this->calculateAttendanceRate($emp, $period);
            if ($rate === null) continue;

            foreach ($items as $item) {
                $item->actual_decimal = $rate;
                $item->status = 'draft';
                $item->save();
                $this->calculationEngine->calculateItem($item);
                $updatedItems++;
            }
            $kpi->calculateProgress();
            $updatedEmployees++;
        }

        return [
            'success' => true,
            'message' => "Absensi disinkronkan: {$updatedItems} indikator kehadiran pada {$updatedEmployees} karyawan.",
            'updated_items' => $updatedItems,
            'updated_employees' => $updatedEmployees,
        ];
    }

    public function calculateAttendanceRate(Employee $emp, KpiPeriod $period): ?float
    {
        $start = $period->start_date->copy();
        $end = $period->end_date->copy();

        if ($start->gt($end)) return null;

        $workingDays = 0;
        $date = $start->copy();
        while ($date->lte($end)) {
            if (!$date->isWeekend()) $workingDays++;
            $date->addDay();
        }

        if ($workingDays === 0) return null;

        $attendedDays = Attendance::where('employee_id', $emp->id)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('status', Attendance::ATTENDED_STATUSES)
            ->distinct('attendance_date')
            ->count('attendance_date');

        return round(($attendedDays / $workingDays) * 100, 2);
    }
}
