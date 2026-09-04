<?php

namespace App\Modules\Assessment;

use App\Models\CustomerFeedback;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\Sparepart;
use App\Models\SparepartRequest;
use App\Modules\Calculation\KpiCalculationEngine;
use App\Support\KpiWorkflow;
use Illuminate\Support\Facades\DB;

class OperationalKpiSyncService
{
    public function __construct(
        protected KpiCalculationEngine $calculationEngine,
        protected AttendanceKpiSyncService $attendanceSync,
        protected InventoryKpiSyncService $inventorySync,
        protected AdminWorkLogKpiSyncService $adminWorkLogSync,
        protected ComplaintKpiSyncService $complaintSync,
        protected CoachingKpiSyncService $coachingSync,
        protected TeamAggregationKpiSyncService $teamAggregationSync
    ) {}

    public function syncPeriodOperationalData(KpiPeriod $period): array
    {
        $kpis = EmployeeKpi::with(['employee.position', 'items'])
            ->where('period_id', $period->id)
            ->get();

        $updatedEmployees = 0;

        foreach ($kpis as $kpi) {
            $emp = $kpi->employee;
            if (!$emp || !$emp->position || !KpiWorkflow::canSystemSyncKpi($kpi)) continue;

            $posCode = $emp->position->code;

            DB::transaction(function () use ($kpi, $emp, $period, $posCode, &$updatedEmployees) {
                $hasUpdates = false;

                if ($posCode === 'POS-TEK') {
                    $hasUpdates = $this->syncTeknisiKpi($kpi, $emp, $period);
                } elseif ($posCode === 'POS-CS') {
                    $hasUpdates = $this->syncPelayanKpi($kpi, $emp, $period);
                } elseif ($posCode === 'POS-GUD') {
                    $hasUpdates = $this->syncGudangKpi($kpi, $emp, $period);
                }

                if ($hasUpdates) {
                    $kpi->calculateProgress();
                    $this->calculationEngine->calculateKpi($kpi, 'operational_sync');
                    $updatedEmployees++;
                }
            });
        }

        // Sub-sistem lain: absensi, inventory (gudang), admin work-log, komplain, coaching, agregasi tim
        $attendanceRes = $this->attendanceSync->syncPeriodAttendanceData($period);
        $inventoryRes = $this->inventorySync->syncPeriodInventoryData($period);
        $adminRes = $this->adminWorkLogSync->syncPeriodWorkLogData($period);
        $complaintRes = $this->complaintSync->syncPeriodComplaintData($period);
        $coachingRes = $this->coachingSync->syncPeriodCoachingData($period);
        $teamRes = $this->teamAggregationSync->syncPeriodTeamAggregation($period);

        return [
            'success' => true,
            'message' => "Sinkronisasi KPI selesai: {$updatedEmployees} karyawan (operasional), "
                . "{$attendanceRes['updated_items']} indikator (absensi), "
                . "{$inventoryRes['updated_items']} indikator (inventory), "
                . "{$adminRes['updated_items']} indikator (admin work-log), "
                . "{$complaintRes['updated_items']} indikator (komplain), "
                . "{$coachingRes['updated_items']} indikator (coaching), "
                . "{$teamRes['updated_items']} indikator (agregasi tim).",
            'updated_count' => $updatedEmployees,
            'attendance' => $attendanceRes,
            'inventory' => $inventoryRes,
            'admin_work_log' => $adminRes,
            'complaint' => $complaintRes,
            'coaching' => $coachingRes,
            'team_aggregation' => $teamRes,
        ];
    }

    public function syncEmployeeKpi(EmployeeKpi $kpi): bool
    {
        $emp = $kpi->employee;
        $period = $kpi->period;
        if (!$emp || !$emp->position || !$period || !KpiWorkflow::canSystemSyncKpi($kpi)) return false;

        $posCode = $emp->position->code;
        $hasUpdates = false;

        DB::transaction(function () use ($kpi, $emp, $period, $posCode, &$hasUpdates) {
            if ($posCode === 'POS-TEK') {
                $hasUpdates = $this->syncTeknisiKpi($kpi, $emp, $period);
            } elseif ($posCode === 'POS-CS') {
                $hasUpdates = $this->syncPelayanKpi($kpi, $emp, $period);
            } elseif ($posCode === 'POS-GUD') {
                $hasUpdates = $this->syncGudangKpi($kpi, $emp, $period);
            }

            if ($hasUpdates) {
                $kpi->calculateProgress();
                $this->calculationEngine->calculateKpi($kpi, 'operational_sync');
            }
        });

        return $hasUpdates;
    }

    protected function syncTeknisiKpi(EmployeeKpi $kpi, Employee $emp, KpiPeriod $period): bool
    {
        $periodStart = $period->start_date->copy()->startOfDay();
        $periodEndExclusive = $period->end_date->copy()->addDay()->startOfDay();
        $tickets = ServiceTicket::where('technician_employee_id', $emp->id)
            ->where('completed_at', '>=', $periodStart)
            ->where('completed_at', '<', $periodEndExclusive)
            ->whereIn('status', ['completed', 'delivered'])
            ->get();

        $completedTickets = $tickets;
        $totalCompleted = $completedTickets->count();
        $hasCompletedData = $totalCompleted > 0;

        // TEK-01: Jumlah Servis Selesai
        $tek01 = $kpi->items->firstWhere('definition_code_snapshot', 'TEK-01');
        if ($tek01) {
            $tek01->actual_decimal = (float) $totalCompleted;
            $tek01->actual_json = [
                'formula' => 'tiket berstatus completed atau delivered dalam periode KPI',
                'completed_tickets' => $totalCompleted,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $period->end_date->toDateString(),
            ];
            $tek01->status = 'draft';
            $tek01->save();
            $this->calculationEngine->calculateItem($tek01);
        }

        // TEK-02: Tingkat Keberhasilan Servis (%)
        $tek02 = $kpi->items->firstWhere('definition_code_snapshot', 'TEK-02');
        if ($tek02) {
            $eligibleResultStatuses = ['success', 'unrepairable', 'warranty_return'];
            $successCount = $completedTickets->where('result_status', 'success')->count();
            $unclassifiedCount = $completedTickets
                ->reject(fn ($ticket) => in_array($ticket->result_status, $eligibleResultStatuses, true))
                ->count();
            $rate = $hasCompletedData && $unclassifiedCount === 0
                ? round(($successCount / $totalCompleted) * 100, 2)
                : null;
            $tek02->actual_decimal = $rate;
            $tek02->actual_json = [
                'formula' => 'tiket berhasil / seluruh tiket selesai × 100',
                'successful_tickets' => $successCount,
                'completed_tickets' => $totalCompleted,
                'unclassified_tickets' => $unclassifiedCount,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $period->end_date->toDateString(),
            ];
            $tek02->status = 'draft';
            $tek02->save();
            $this->calculationEngine->calculateItem($tek02);
        }

        // TEK-03: Tingkat Retur / Komplain Servis (%)
        $tek03 = $kpi->items->firstWhere('definition_code_snapshot', 'TEK-03');
        if ($tek03) {
            $returnCount = $completedTickets->where('is_warranty_return', true)->count();
            $unclassifiedCount = $completedTickets
                ->reject(fn ($ticket) => in_array($ticket->result_status, ['success', 'unrepairable', 'warranty_return'], true))
                ->count();
            $returnRate = $hasCompletedData && $unclassifiedCount === 0
                ? round(($returnCount / $totalCompleted) * 100, 2)
                : null;
            $tek03->actual_decimal = $returnRate;
            $tek03->actual_json = [
                'formula' => 'tiket retur garansi / seluruh tiket selesai × 100',
                'warranty_return_tickets' => $returnCount,
                'completed_tickets' => $totalCompleted,
                'unclassified_tickets' => $unclassifiedCount,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $period->end_date->toDateString(),
            ];
            $tek03->status = 'draft';
            $tek03->save();
            $this->calculationEngine->calculateItem($tek03);
        }

        // TEK-04: Ketepatan Waktu Pengerjaan (%)
        $tek04 = $kpi->items->firstWhere('definition_code_snapshot', 'TEK-04');
        if ($tek04) {
            $timedTickets = $completedTickets->filter(function ($t) {
                return $t->estimated_completion_at && $t->completed_at;
            });
            $ontimeCount = $timedTickets->filter(function ($t) {
                return $t->completed_at <= $t->estimated_completion_at;
            })->count();
            $ontimeRate = $timedTickets->isNotEmpty()
                ? round(($ontimeCount / $timedTickets->count()) * 100, 2)
                : null;
            $tek04->actual_decimal = $ontimeRate;
            $tek04->actual_json = [
                'formula' => 'tiket selesai tepat waktu / tiket dengan estimasi dan waktu selesai × 100',
                'ontime_tickets' => $ontimeCount,
                'timed_completed_tickets' => $timedTickets->count(),
                'excluded_without_timestamps' => $totalCompleted - $timedTickets->count(),
                'period_start' => $periodStart->toDateString(),
                'period_end' => $period->end_date->toDateString(),
            ];
            $tek04->status = 'draft';
            $tek04->save();
            $this->calculationEngine->calculateItem($tek04);
        }

        // TEK-07: Kelengkapan Laporan Servis (%)
        $tek07 = $kpi->items->firstWhere('definition_code_snapshot', 'TEK-07');
        if ($tek07) {
            $completeReportCount = $completedTickets->filter(function ($t) {
                return !empty($t->diagnosis_notes)
                    && !empty($t->action_notes)
                    && is_array($t->qc_checklist_json)
                    && collect($t->qc_checklist_json)->every(fn ($value) => is_bool($value));
            })->count();
            $reportRate = $hasCompletedData ? round(($completeReportCount / $totalCompleted) * 100, 2) : null;
            $tek07->actual_decimal = $reportRate;
            $tek07->status = 'verified'; // automatically verified by system
            $tek07->save();
            $this->calculationEngine->calculateItem($tek07);
        }

        return true;
    }

    protected function syncPelayanKpi(EmployeeKpi $kpi, Employee $emp, KpiPeriod $period): bool
    {
        $periodStart = $period->start_date->copy()->startOfDay();
        $periodEndExclusive = $period->end_date->copy()->addDay()->startOfDay();
        $feedbacks = CustomerFeedback::where('cs_employee_id', $emp->id)
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEndExclusive)
            ->get();

        $intakeTickets = ServiceTicket::where('intake_by_employee_id', $emp->id)
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEndExclusive)
            ->get();

        // CS-01: CSAT / Kepuasan Pelanggan Pelayan (%)
        $cs01 = $kpi->items->firstWhere('definition_code_snapshot', 'CS-01');
        if ($cs01) {
            $avgRating = $feedbacks->avg('rating');
            $csatPercent = $feedbacks->isNotEmpty() ? round(($avgRating / 5.0) * 100, 2) : null;
            $cs01->actual_decimal = $csatPercent;
            $cs01->status = 'draft';
            $cs01->save();
            $this->calculationEngine->calculateItem($cs01);
        }

        // CS-03: Akurasi Input Order / Nota Servis (%)
        $cs03 = $kpi->items->firstWhere('definition_code_snapshot', 'CS-03');
        if ($cs03) {
            $validCount = $intakeTickets->filter(function ($t) {
                return !empty($t->customer_phone) && !empty($t->device_brand) && !empty($t->initial_complaint);
            })->count();
            $accuracy = $intakeTickets->isNotEmpty() ? round(($validCount / $intakeTickets->count()) * 100, 2) : null;
            $cs03->actual_decimal = $accuracy;
            $cs03->status = 'draft';
            $cs03->save();
            $this->calculationEngine->calculateItem($cs03);
        }

        // CS-04: Follow-up Status Pelanggan oleh Pelayan (%)
        $cs04 = $kpi->items->firstWhere('definition_code_snapshot', 'CS-04');
        if ($cs04) {
            $ontimeFollowUp = $feedbacks->where('follow_up_ontime', true)->count();
            $followUpRate = $feedbacks->isNotEmpty() ? round(($ontimeFollowUp / $feedbacks->count()) * 100, 2) : null;
            $cs04->actual_decimal = $followUpRate;
            $cs04->status = 'draft';
            $cs04->save();
            $this->calculationEngine->calculateItem($cs04);
        }

        return true;
    }

    protected function syncGudangKpi(EmployeeKpi $kpi, Employee $emp, KpiPeriod $period): bool
    {
        $periodStart = $period->start_date->copy()->startOfDay();
        $periodEndExclusive = $period->end_date->copy()->addDay()->startOfDay();
        $requests = SparepartRequest::where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEndExclusive)
            ->get();
        $fulfilled = $requests->where('status', 'fulfilled');

        // GUD-03: Kecepatan Penyediaan Sparepart (%)
        $gud03 = $kpi->items->firstWhere('definition_code_snapshot', 'GUD-03');
        if ($gud03) {
            $fastFulfilled = $fulfilled->filter(function ($r) {
                if (!$r->requested_at || !$r->fulfilled_at) return false;
                return $r->fulfilled_at->diffInMinutes($r->requested_at) <= 15; // SLA 15 menit
            })->count();
            $rate = $fulfilled->isNotEmpty() ? round(($fastFulfilled / $fulfilled->count()) * 100, 2) : null;
            $gud03->actual_decimal = $rate;
            $gud03->status = 'draft';
            $gud03->save();
            $this->calculationEngine->calculateItem($gud03);
        }

        // GUD-04: Kelengkapan Stok Sparepart Kritis (%)
        $gud04 = $kpi->items->firstWhere('definition_code_snapshot', 'GUD-04');
        if ($gud04) {
            $criticalParts = Sparepart::where('is_critical', true)->get();
            $inStockCritical = $criticalParts->where('stock_quantity', '>', 0)->count();
            $stockRate = $criticalParts->isNotEmpty() ? round(($inStockCritical / $criticalParts->count()) * 100, 2) : null;
            $gud04->actual_decimal = $stockRate;
            $gud04->status = 'draft';
            $gud04->save();
            $this->calculationEngine->calculateItem($gud04);
        }

        return true;
    }
}
