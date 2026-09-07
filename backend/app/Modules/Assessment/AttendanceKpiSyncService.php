<?php

namespace App\Modules\Assessment;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\KpiDailyEntry;
use App\Models\KpiPeriod;
use App\Modules\Calculation\KpiCalculationEngine;
use App\Support\KpiWorkflow;

/**
 * Subsistem Absensi → KPI Kehadiran & Disiplin.
 * Feed: ADM-05, KSR-06, GUD-07, CS-06 Pelayan (dan SUP-03 via agregasi).
 *
 * Rate = hari hadir / hari kerja yang wajib dinilai × 100.
 * Hari kerja = Senin–Jumat. Hadir/Terlambat menjadi pembilang; Izin/Sakit
 * dikeluarkan dari pembagi; hari yang belum ditutup belum dihitung sebagai Alpha.
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
            if (! $emp) {
                continue;
            }

            $items = $kpi->items->whereIn('definition_code_snapshot', self::ATTENDANCE_ITEM_CODES);
            if ($items->isEmpty()) {
                continue;
            }

            if (! KpiWorkflow::canSystemSyncKpi($kpi)) {
                continue;
            }

            foreach ($items as $item) {
                $rate = $this->calculateAttendanceRate($emp, $period, $item);
                $item->actual_decimal = $rate;
                $item->status = 'draft';
                $item->save();
                $this->calculationEngine->calculateItem($item);
                $this->syncDailyEntries($item, $emp, $period);
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

    public function calculateAttendanceRate(Employee $emp, KpiPeriod $period, ?EmployeeKpiItem $item = null): ?float
    {
        $start = $period->start_date->copy();
        $end = $period->end_date->copy()->min(now()->startOfDay());

        if ($start->gt($end)) {
            return null;
        }

        $workingDays = 0;
        $date = $start->copy();
        while ($date->lte($end)) {
            if (! $date->isWeekend()) {
                $workingDays++;
            }
            $date->addDay();
        }

        if ($workingDays === 0) {
            return null;
        }

        $statusesByDate = Attendance::where('employee_id', $emp->id)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->get(['attendance_date', 'status'])
            ->keyBy(fn (Attendance $attendance): string => $attendance->attendance_date->toDateString());
        $managerOverrides = $item ? KpiDailyEntry::where('employee_kpi_item_id', $item->id)
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->where('manager_status', 'approved')
            ->get(['entry_date', 'manager_actual_json'])
            ->mapWithKeys(fn (KpiDailyEntry $entry): array => [
                $entry->entry_date->toDateString() => data_get($entry->manager_actual_json, 'attendance_status'),
            ])
            ->filter()
            : collect();

        $eligibleDays = 0;
        $attendedDays = 0;
        $date = $start->copy();
        while ($date->lte($end)) {
            if ($date->isWeekend()) {
                $date->addDay();

                continue;
            }

            $status = $managerOverrides->get($date->toDateString())
                ?? $statusesByDate->get($date->toDateString())?->status;
            if ($status === null) {
                $date->addDay();

                continue;
            }
            if (! in_array($status, Attendance::EXCUSED_STATUSES, true)) {
                $eligibleDays++;
                if (in_array($status, Attendance::WORKED_STATUSES, true)) {
                    $attendedDays++;
                }
            }
            $date->addDay();
        }

        if ($eligibleDays === 0) {
            return null;
        }

        return round(($attendedDays / $eligibleDays) * 100, 2);
    }

    private function syncDailyEntries(EmployeeKpiItem $item, Employee $emp, KpiPeriod $period): void
    {
        $start = $period->start_date->copy();
        $end = $period->end_date->copy()->min(now()->startOfDay());
        if ($start->gt($end)) {
            return;
        }

        $statuses = Attendance::where('employee_id', $emp->id)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->get(['attendance_date', 'status'])
            ->keyBy(fn (Attendance $attendance): string => $attendance->attendance_date->toDateString());

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $status = $statuses->get($date->toDateString())?->status;
            $entry = KpiDailyEntry::where('employee_kpi_item_id', $item->id)
                ->whereDate('entry_date', $date->toDateString())
                ->first();
            $reviewedStatus = data_get($entry, 'manager_actual_json.attendance_status')
                ?? data_get($entry, 'supervisor_actual_json.attendance_status');
            $pendingAttendance = $status === null;
            if ($pendingAttendance && ! $entry) {
                $entry = new KpiDailyEntry([
                    'employee_kpi_item_id' => $item->id,
                    'entry_date' => $date->toDateString(),
                ]);
            }

            if ($date->isWeekend() || in_array($status, Attendance::EXCUSED_STATUSES, true) || $pendingAttendance) {
                $reviewedSameStatus = $reviewedStatus !== null && $reviewedStatus === $status;
                if ($entry && ! $reviewedSameStatus && ($entry->system_actual_decimal !== null
                    || $entry->employee_actual_decimal !== null
                    || $entry->employee_actual_json !== null
                    || $entry->entry_status !== 'draft'
                    || $pendingAttendance
                    || $entry->supervisor_status !== 'pending'
                    || $entry->manager_status !== 'pending')) {
                    $entry->system_actual_decimal = null;
                    $entry->system_actual_json = [
                        'attendance_status' => $pendingAttendance ? 'not_recorded' : ($status ?? Attendance::STATUS_ABSENT),
                        'excluded_from_ratio' => $date->isWeekend() || in_array($status, Attendance::EXCUSED_STATUSES, true),
                        'pending_supervisor' => $pendingAttendance,
                    ];
                    $entry->employee_actual_decimal = null;
                    $entry->employee_actual_json = null;
                    $entry->employee_note = null;
                    $entry->employee_entered_by = null;
                    $entry->employee_submitted_at = null;
                    $entry->entry_status = $pendingAttendance ? 'submitted' : 'draft';
                    $entry->supervisor_actual_decimal = null;
                    $entry->supervisor_actual_json = null;
                    $entry->supervisor_answers_json = null;
                    $entry->supervisor_score_percentage = null;
                    $entry->supervisor_note = null;
                    $entry->supervisor_assessed_by = null;
                    $entry->supervisor_status = 'pending';
                    $entry->supervisor_assessed_at = null;
                    $entry->manager_actual_decimal = null;
                    $entry->manager_actual_json = null;
                    $entry->manager_answers_json = null;
                    $entry->manager_score_percentage = null;
                    $entry->manager_note = null;
                    $entry->manager_assessed_by = null;
                    $entry->manager_status = 'pending';
                    $entry->manager_assessed_at = null;
                    if ($entry->isDirty()) {
                        $entry->row_version = ((int) ($entry->row_version ?: 0)) + 1;
                        $entry->save();
                    }
                }

                continue;
            }

            $value = in_array($status, Attendance::WORKED_STATUSES, true) ? 100.0 : 0.0;
            $entry ??= KpiDailyEntry::firstOrNew([
                'employee_kpi_item_id' => $item->id,
                'entry_date' => $date->toDateString(),
            ]);
            $changed = ($reviewedStatus === null || $reviewedStatus !== $status)
                && ($entry->system_actual_decimal === null
                    || abs((float) $entry->system_actual_decimal - $value) > 0.000001);
            $entry->system_actual_decimal = $value;
            $entry->system_actual_json = ['attendance_status' => $status, 'excluded_from_ratio' => false];
            $entry->entry_status = 'submitted';
            if ($changed) {
                $entry->supervisor_status = 'pending';
                $entry->manager_status = 'pending';
                $entry->supervisor_actual_decimal = null;
                $entry->manager_actual_decimal = null;
            }
            if ($entry->isDirty()) {
                $entry->row_version = ((int) ($entry->row_version ?: 0)) + 1;
                $entry->save();
            }
        }
    }
}
