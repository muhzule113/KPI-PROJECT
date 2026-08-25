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
use Illuminate\Support\Facades\DB;

class OperationalKpiSyncService
{
    public function __construct(
        protected KpiCalculationEngine $calculationEngine
    ) {}

    public function syncPeriodOperationalData(KpiPeriod $period): array
    {
        $kpis = EmployeeKpi::with(['employee.position', 'items'])
            ->where('period_id', $period->id)
            ->get();

        $updatedEmployees = 0;

        foreach ($kpis as $kpi) {
            $emp = $kpi->employee;
            if (!$emp || !$emp->position) continue;

            $posCode = $emp->position->code;

            DB::transaction(function () use ($kpi, $emp, $period, $posCode, &$updatedEmployees) {
                $hasUpdates = false;

                if ($posCode === 'POS-TEK') {
                    $hasUpdates = $this->syncTeknisiKpi($kpi, $emp, $period);
                } elseif ($posCode === 'POS-CS') {
                    $hasUpdates = $this->syncCustomerServiceKpi($kpi, $emp, $period);
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

        return [
            'success' => true,
            'message' => "Data operasional berhasil disinkronkan ke {$updatedEmployees} KPI karyawan aktif.",
            'updated_count' => $updatedEmployees,
        ];
    }

    public function syncEmployeeKpi(EmployeeKpi $kpi): bool
    {
        $emp = $kpi->employee;
        $period = $kpi->period;
        if (!$emp || !$emp->position || !$period) return false;

        $posCode = $emp->position->code;
        $hasUpdates = false;

        DB::transaction(function () use ($kpi, $emp, $period, $posCode, &$hasUpdates) {
            if ($posCode === 'POS-TEK') {
                $hasUpdates = $this->syncTeknisiKpi($kpi, $emp, $period);
            } elseif ($posCode === 'POS-CS') {
                $hasUpdates = $this->syncCustomerServiceKpi($kpi, $emp, $period);
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
        $tickets = ServiceTicket::where('technician_employee_id', $emp->id)
            ->where(function ($q) use ($period) {
                $q->where('period_id', $period->id)
                  ->orWhereBetween('created_at', [$period->start_date, $period->end_date]);
            })
            ->get();

        $completedTickets = $tickets->whereIn('status', ['completed', 'delivered']);
        $totalCompleted = $completedTickets->count();

        // TEK-01: Jumlah Servis Selesai
        $tek01 = $kpi->items->firstWhere('definition_code_snapshot', 'TEK-01');
        if ($tek01) {
            $tek01->actual_decimal = (float) $totalCompleted;
            $tek01->status = 'draft';
            $tek01->save();
            $this->calculationEngine->calculateItem($tek01);
        }

        // TEK-02: Tingkat Keberhasilan Servis (%)
        $tek02 = $kpi->items->firstWhere('definition_code_snapshot', 'TEK-02');
        if ($tek02) {
            $successCount = $completedTickets->where('result_status', 'success')->count();
            $rate = $totalCompleted > 0 ? round(($successCount / $totalCompleted) * 100, 2) : 100.0;
            $tek02->actual_decimal = $rate;
            $tek02->status = 'draft';
            $tek02->save();
            $this->calculationEngine->calculateItem($tek02);
        }

        // TEK-03: Tingkat Retur / Komplain Servis (%)
        $tek03 = $kpi->items->firstWhere('definition_code_snapshot', 'TEK-03');
        if ($tek03) {
            $returnCount = $completedTickets->where('is_warranty_return', true)->count();
            $returnRate = $totalCompleted > 0 ? round(($returnCount / $totalCompleted) * 100, 2) : 0.0;
            $tek03->actual_decimal = $returnRate;
            $tek03->status = 'draft';
            $tek03->save();
            $this->calculationEngine->calculateItem($tek03);
        }

        // TEK-04: Ketepatan Waktu Pengerjaan (%)
        $tek04 = $kpi->items->firstWhere('definition_code_snapshot', 'TEK-04');
        if ($tek04) {
            $ontimeCount = $completedTickets->filter(function ($t) {
                if (!$t->estimated_completion_at || !$t->completed_at) return true;
                return $t->completed_at <= $t->estimated_completion_at;
            })->count();
            $ontimeRate = $totalCompleted > 0 ? round(($ontimeCount / $totalCompleted) * 100, 2) : 100.0;
            $tek04->actual_decimal = $ontimeRate;
            $tek04->status = 'draft';
            $tek04->save();
            $this->calculationEngine->calculateItem($tek04);
        }

        // TEK-07: Kelengkapan Laporan Servis (%)
        $tek07 = $kpi->items->firstWhere('definition_code_snapshot', 'TEK-07');
        if ($tek07) {
            $completeReportCount = $completedTickets->filter(function ($t) {
                return !empty($t->diagnosis_notes) && !empty($t->action_notes) && !empty($t->qc_checklist_json);
            })->count();
            $reportRate = $totalCompleted > 0 ? round(($completeReportCount / $totalCompleted) * 100, 2) : 100.0;
            $tek07->actual_decimal = $reportRate;
            $tek07->status = 'verified'; // automatically verified by system
            $tek07->save();
            $this->calculationEngine->calculateItem($tek07);
        }

        return true;
    }

    protected function syncCustomerServiceKpi(EmployeeKpi $kpi, Employee $emp, KpiPeriod $period): bool
    {
        $feedbacks = CustomerFeedback::where('cs_employee_id', $emp->id)
            ->whereBetween('created_at', [$period->start_date, $period->end_date])
            ->get();

        $intakeTickets = ServiceTicket::where('intake_by_employee_id', $emp->id)
            ->whereBetween('created_at', [$period->start_date, $period->end_date])
            ->get();

        // CS-01: CSAT / Kepuasan Pelanggan (%)
        $cs01 = $kpi->items->firstWhere('definition_code_snapshot', 'CS-01');
        if ($cs01) {
            $avgRating = $feedbacks->avg('rating');
            $csatPercent = $avgRating ? round(($avgRating / 5.0) * 100, 2) : 95.0;
            $cs01->actual_decimal = $csatPercent;
            $cs01->status = 'draft';
            $cs01->save();
            $this->calculationEngine->calculateItem($cs01);
        }

        // CS-03: Akurasi Input Order / Tiket (%)
        $cs03 = $kpi->items->firstWhere('definition_code_snapshot', 'CS-03');
        if ($cs03) {
            $validCount = $intakeTickets->filter(function ($t) {
                return !empty($t->customer_phone) && !empty($t->device_brand) && !empty($t->initial_complaint);
            })->count();
            $accuracy = $intakeTickets->isNotEmpty() ? round(($validCount / $intakeTickets->count()) * 100, 2) : 98.0;
            $cs03->actual_decimal = $accuracy;
            $cs03->status = 'draft';
            $cs03->save();
            $this->calculationEngine->calculateItem($cs03);
        }

        // CS-04: Follow-up Status Pelanggan (%)
        $cs04 = $kpi->items->firstWhere('definition_code_snapshot', 'CS-04');
        if ($cs04) {
            $ontimeFollowUp = $feedbacks->where('follow_up_ontime', true)->count();
            $followUpRate = $feedbacks->isNotEmpty() ? round(($ontimeFollowUp / $feedbacks->count()) * 100, 2) : 95.0;
            $cs04->actual_decimal = $followUpRate;
            $cs04->status = 'draft';
            $cs04->save();
            $this->calculationEngine->calculateItem($cs04);
        }

        // CS-05: Jumlah Komplain Pelanggan
        $cs05 = $kpi->items->firstWhere('definition_code_snapshot', 'CS-05');
        if ($cs05) {
            $complaintsCount = $feedbacks->where('rating', '<=', 2)->count() + $intakeTickets->where('is_warranty_return', true)->count();
            $cs05->actual_decimal = (float) $complaintsCount;
            $cs05->status = 'draft';
            $cs05->save();
            $this->calculationEngine->calculateItem($cs05);
        }

        return true;
    }

    protected function syncGudangKpi(EmployeeKpi $kpi, Employee $emp, KpiPeriod $period): bool
    {
        $requests = SparepartRequest::whereBetween('created_at', [$period->start_date, $period->end_date])->get();
        $fulfilled = $requests->where('status', 'fulfilled');

        // GUD-03: Kecepatan Penyediaan Sparepart (%)
        $gud03 = $kpi->items->firstWhere('definition_code_snapshot', 'GUD-03');
        if ($gud03) {
            $fastFulfilled = $fulfilled->filter(function ($r) {
                if (!$r->requested_at || !$r->fulfilled_at) return true;
                return $r->fulfilled_at->diffInMinutes($r->requested_at) <= 15; // SLA 15 menit
            })->count();
            $rate = $fulfilled->isNotEmpty() ? round(($fastFulfilled / $fulfilled->count()) * 100, 2) : 96.0;
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
            $stockRate = $criticalParts->isNotEmpty() ? round(($inStockCritical / $criticalParts->count()) * 100, 2) : 100.0;
            $gud04->actual_decimal = $stockRate;
            $gud04->status = 'draft';
            $gud04->save();
            $this->calculationEngine->calculateItem($gud04);
        }

        return true;
    }
}
