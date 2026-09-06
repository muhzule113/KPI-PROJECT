<?php

namespace App\Modules\Assessment;

use App\Models\CustomerFeedback;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\FeedbackFollowUp;
use App\Models\KpiDailyEntry;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\Sparepart;
use App\Models\SparepartRequest;
use App\Modules\Calculation\KpiCalculationEngine;
use App\Modules\Reporting\ReportSubmissionService;
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
        protected TeamAggregationKpiSyncService $teamAggregationSync,
        protected CashierKpiSyncService $cashierSync,
        protected ReportSubmissionService $reportSubmissionSync,
    ) {}

    public function syncPeriodOperationalData(KpiPeriod $period): array
    {
        $kpis = EmployeeKpi::with(['employee.position', 'items'])
            ->where(fn ($query) => $query->where('period_id', $period->id)->orWhereNull('period_id'))
            ->get();

        $updatedEmployees = 0;

        foreach ($kpis as $kpi) {
            $emp = $kpi->employee;
            if (! $emp || ! KpiWorkflow::canSystemSyncKpi($kpi)) {
                continue;
            }

            $posCode = $kpi->position_code_snapshot;

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
        $cashierRes = $this->cashierSync->syncPeriod($period);
        $reportSubmissionRes = $this->reportSubmissionSync->syncPeriod($period);

        return [
            'success' => true,
            'message' => "Sinkronisasi KPI selesai: {$updatedEmployees} karyawan (operasional), "
                ."{$attendanceRes['updated_items']} indikator (absensi), "
                ."{$inventoryRes['updated_items']} indikator (inventory), "
                ."{$adminRes['updated_items']} indikator (admin work-log), "
                ."{$complaintRes['updated_items']} indikator (komplain), "
                ."{$coachingRes['updated_items']} indikator (coaching), "
                ."{$teamRes['updated_items']} indikator (agregasi tim).",
            'updated_count' => $updatedEmployees,
            'attendance' => $attendanceRes,
            'inventory' => $inventoryRes,
            'admin_work_log' => $adminRes,
            'complaint' => $complaintRes,
            'coaching' => $coachingRes,
            'team_aggregation' => $teamRes,
            'cashier' => $cashierRes,
            'report_submissions' => $reportSubmissionRes,
        ];
    }

    public function syncEmployeeKpi(EmployeeKpi $kpi): bool
    {
        $emp = $kpi->employee;
        $period = $kpi->period;
        if (! $emp || ! $period || ! KpiWorkflow::canSystemSyncKpi($kpi)) {
            return false;
        }

        $posCode = $kpi->position_code_snapshot;
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
        $ticketsByDay = $completedTickets->groupBy(fn (ServiceTicket $ticket): string => $ticket->completed_at->toDateString());
        $returnEvents = ServiceTicket::query()
            ->where('is_warranty_return', true)
            ->whereNotNull('warranty_returned_from_ticket_id')
            ->where('warranty_review_status', 'approved')
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEndExclusive)
            ->whereHas('originalTicket', fn ($query) => $query->where('technician_employee_id', $emp->id))
            ->get()
            ->unique('warranty_returned_from_ticket_id')
            ->values();
        $returnEventsByDay = $returnEvents->groupBy(fn (ServiceTicket $ticket): string => $ticket->created_at->toDateString());

        // TEK-01: Jumlah Servis Selesai
        $tek01 = $kpi->items->firstWhere('definition_code_snapshot', 'TEK-01');
        if ($tek01 && $tek01->acceptsSystemCalculatedValue()) {
            $tek01->actual_decimal = $hasCompletedData ? (float) $totalCompleted : null;
            $tek01->actual_json = [
                '_system_calculated' => true,
                'formula' => 'tiket berstatus completed atau delivered dalam periode KPI',
                'completed_tickets' => $totalCompleted,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $period->end_date->toDateString(),
            ];
            $tek01->status = 'draft';
            $tek01->save();
            $this->calculationEngine->calculateItem($tek01);
            foreach ($ticketsByDay as $date => $dayTickets) {
                $this->writeDailySystemValue($tek01, $date, (float) $dayTickets->count(), ['completed_tickets' => $dayTickets->count()]);
            }
        }

        // TEK-02: Tingkat Keberhasilan Servis (%)
        $tek02 = $kpi->items->firstWhere('definition_code_snapshot', 'TEK-02');
        if ($tek02 && $tek02->acceptsSystemCalculatedValue()) {
            $eligibleResultStatuses = ['success', 'unrepairable', 'customer_declined', 'warranty_return'];
            $successCount = $completedTickets->where('result_status', 'success')->count();
            $unclassifiedCount = $completedTickets
                ->reject(fn ($ticket) => in_array($ticket->result_status, $eligibleResultStatuses, true))
                ->count();
            $rate = $hasCompletedData && $unclassifiedCount === 0
                ? round(($successCount / $totalCompleted) * 100, 2)
                : null;
            $tek02->actual_decimal = $rate;
            $tek02->actual_json = [
                '_system_calculated' => true,
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
            foreach ($ticketsByDay as $date => $dayTickets) {
                $dayTotal = $dayTickets->count();
                $daySuccess = $dayTickets->where('result_status', 'success')->count();
                $dayUnclassified = $dayTickets->reject(fn (ServiceTicket $ticket): bool => in_array($ticket->result_status, $eligibleResultStatuses, true))->count();
                $this->writeDailySystemValue(
                    $tek02,
                    $date,
                    $dayTotal > 0 && $dayUnclassified === 0 ? ($daySuccess / $dayTotal) * 100 : null,
                    ['successful_tickets' => $daySuccess, 'completed_tickets' => $dayTotal, 'unclassified_tickets' => $dayUnclassified]
                );
            }
        }

        // TEK-03: Tingkat Retur / Komplain Servis (%)
        $tek03 = $kpi->items->firstWhere('definition_code_snapshot', 'TEK-03');
        if ($tek03) {
            $returnCount = $returnEvents->count();
            $unclassifiedCount = $completedTickets
                ->reject(fn ($ticket) => in_array($ticket->result_status, ['success', 'unrepairable', 'customer_declined', 'warranty_return'], true))
                ->count();
            $returnRate = $hasCompletedData && $unclassifiedCount === 0
                ? round(($returnCount / $totalCompleted) * 100, 2)
                : null;
            $tek03->actual_decimal = $returnRate;
            $tek03->actual_json = [
                'formula' => 'tiket retur garansi / seluruh tiket selesai × 100',
                'warranty_return_tickets' => $returnCount,
                'completed_tickets' => $totalCompleted,
                'return_event_keys' => $returnEvents->pluck('warranty_returned_from_ticket_id')->values()->all(),
                'unclassified_tickets' => $unclassifiedCount,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $period->end_date->toDateString(),
            ];
            $tek03->status = 'draft';
            $tek03->save();
            $this->calculationEngine->calculateItem($tek03);
            foreach ($ticketsByDay as $date => $dayTickets) {
                $dayTotal = $dayTickets->count();
                $dayReturns = $returnEventsByDay->get($date, collect())->count();
                $dayUnclassified = $dayTickets->reject(fn (ServiceTicket $ticket): bool => in_array($ticket->result_status, ['success', 'unrepairable', 'customer_declined', 'warranty_return'], true))->count();
                $this->writeDailySystemValue(
                    $tek03,
                    $date,
                    $dayTotal > 0 && $dayUnclassified === 0 ? ($dayReturns / $dayTotal) * 100 : null,
                    ['warranty_return_tickets' => $dayReturns, 'completed_tickets' => $dayTotal, 'unclassified_tickets' => $dayUnclassified]
                );
            }
        }

        // TEK-04: Ketepatan Waktu Pengerjaan (%)
        $tek04 = $kpi->items->firstWhere('definition_code_snapshot', 'TEK-04');
        if ($tek04 && $tek04->acceptsSystemCalculatedValue()) {
            $timedTickets = $completedTickets->filter(function ($t) {
                return ($t->sla_due_at ?? $t->estimated_completion_at) && $t->completed_at;
            });
            $ontimeCount = $timedTickets->filter(function ($t) {
                return $t->completed_at <= ($t->sla_due_at ?? $t->estimated_completion_at);
            })->count();
            $ontimeRate = $timedTickets->isNotEmpty()
                ? round(($ontimeCount / $timedTickets->count()) * 100, 2)
                : null;
            $tek04->actual_decimal = $ontimeRate;
            $tek04->actual_json = [
                '_system_calculated' => true,
                'formula' => 'tiket selesai tepat waktu / tiket dengan estimasi dan waktu selesai × 100',
                'ontime_tickets' => $ontimeCount,
                'timed_completed_tickets' => $timedTickets->count(),
                'excluded_without_timestamps' => $totalCompleted - $timedTickets->count(),
                'sparepart_wait_minutes' => (int) $completedTickets->sum('sparepart_wait_minutes'),
                'period_start' => $periodStart->toDateString(),
                'period_end' => $period->end_date->toDateString(),
            ];
            $tek04->status = 'draft';
            $tek04->save();
            $this->calculationEngine->calculateItem($tek04);
            foreach ($ticketsByDay as $date => $dayTickets) {
                $timed = $dayTickets->filter(fn (ServiceTicket $ticket): bool => ($ticket->sla_due_at ?? $ticket->estimated_completion_at) && $ticket->completed_at);
                $ontime = $timed->filter(fn (ServiceTicket $ticket): bool => $ticket->completed_at <= ($ticket->sla_due_at ?? $ticket->estimated_completion_at))->count();
                $this->writeDailySystemValue($tek04, $date, $timed->isNotEmpty() ? ($ontime / $timed->count()) * 100 : null, ['ontime_tickets' => $ontime, 'timed_completed_tickets' => $timed->count()]);
            }
        }

        // TEK-07: Kelengkapan Laporan Servis (%)
        $tek07 = $kpi->items->firstWhere('definition_code_snapshot', 'TEK-07');
        if ($tek07) {
            $completeReportCount = $completedTickets->filter(function ($t) {
                $qc = is_array($t->qc_checklist_json) ? $t->qc_checklist_json : [];
                $hasRequiredQc = ! array_diff(ServiceTicket::REQUIRED_QC_KEYS, array_keys($qc))
                    && collect(ServiceTicket::REQUIRED_QC_KEYS)->every(fn (string $key): bool => $qc[$key] === true || is_bool($qc[$key]));
                $requiresEvidence = in_array($t->result_status, [ServiceTicket::RESULT_SUCCESS, ServiceTicket::RESULT_UNREPAIRABLE], true);

                return ! empty($t->diagnosis_notes)
                    && ! empty($t->action_notes)
                    && $hasRequiredQc
                    && (! $requiresEvidence || collect($t->technical_evidence_json)->contains(
                        fn (array $evidence): bool => empty($evidence['file_path']) || ($evidence['scan_status'] ?? null) === 'clean'
                    ));
            })->count();
            $reportRate = $hasCompletedData ? round(($completeReportCount / $totalCompleted) * 100, 2) : null;
            $tek07->actual_decimal = $reportRate;
            $tek07->status = 'verified'; // automatically verified by system
            $tek07->save();
            $this->calculationEngine->calculateItem($tek07);
            foreach ($ticketsByDay as $date => $dayTickets) {
                $complete = $dayTickets->filter(function (ServiceTicket $ticket): bool {
                    $qc = is_array($ticket->qc_checklist_json) ? $ticket->qc_checklist_json : [];
                    $hasRequiredQc = ! array_diff(ServiceTicket::REQUIRED_QC_KEYS, array_keys($qc))
                        && collect(ServiceTicket::REQUIRED_QC_KEYS)->every(fn (string $key): bool => $qc[$key] === true || is_bool($qc[$key]));
                    $requiresEvidence = in_array($ticket->result_status, [ServiceTicket::RESULT_SUCCESS, ServiceTicket::RESULT_UNREPAIRABLE], true);

                    return ! empty($ticket->diagnosis_notes)
                        && ! empty($ticket->action_notes)
                        && $hasRequiredQc
                        && (! $requiresEvidence || collect($ticket->technical_evidence_json)->contains(
                            fn (array $evidence): bool => empty($evidence['file_path']) || ($evidence['scan_status'] ?? null) === 'clean'
                        ));
                })->count();
                $this->writeDailySystemValue($tek07, $date, $dayTickets->isNotEmpty() ? ($complete / $dayTickets->count()) * 100 : null, ['complete_reports' => $complete, 'completed_tickets' => $dayTickets->count()]);
            }
        }

        return true;
    }

    private function writeDailySystemValue(EmployeeKpiItem $item, string $date, ?float $value, array $meta = []): void
    {
        $entry = KpiDailyEntry::where('employee_kpi_item_id', $item->id)->whereDate('entry_date', $date)->first()
            ?? new KpiDailyEntry(['employee_kpi_item_id' => $item->id, 'entry_date' => $date]);
        $entry->system_actual_decimal = $value;
        $entry->system_actual_json = $meta;
        $changed = $entry->isDirty(['system_actual_decimal', 'system_actual_json']);
        if ($item->isSystemSourced() || $entry->employee_submitted_at !== null) {
            $entry->entry_status = 'submitted';
        } elseif (! $entry->entry_status) {
            $entry->entry_status = 'draft';
        }
        if ($changed) {
            $entry->supervisor_status = 'pending';
            $entry->supervisor_assessed_by = null;
            $entry->supervisor_assessed_at = null;
            $entry->supervisor_actual_decimal = null;
            $entry->supervisor_actual_json = null;
            $entry->supervisor_answers_json = null;
            $entry->supervisor_score_percentage = null;
            $entry->manager_status = 'pending';
            $entry->manager_assessed_by = null;
            $entry->manager_assessed_at = null;
            $entry->manager_actual_decimal = null;
            $entry->manager_actual_json = null;
            $entry->manager_answers_json = null;
            $entry->manager_score_percentage = null;
        }
        if ($entry->isDirty()) {
            $entry->row_version = ((int) ($entry->row_version ?: 0)) + 1;
            $entry->save();
        }
    }

    protected function syncPelayanKpi(EmployeeKpi $kpi, Employee $emp, KpiPeriod $period): bool
    {
        $periodStart = $period->start_date->copy()->startOfDay();
        $periodEndExclusive = $period->end_date->copy()->addDay()->startOfDay();
        $feedbacks = CustomerFeedback::with('ticket')
            ->where('cs_employee_id', $emp->id)
            ->whereHas('ticket', fn ($query) => $query
                ->where('status', ServiceTicket::STATUS_DELIVERED)
                ->whereNotNull('delivered_at')
                ->where(fn ($ticketQuery) => $ticketQuery->where('period_id', $period->id)->orWhereNull('period_id'))
                ->where('delivered_at', '>=', $periodStart)
                ->where('delivered_at', '<', $periodEndExclusive))
            ->get()
            ->filter(fn (CustomerFeedback $feedback): bool => $feedback->ticket?->delivered_at
                && $feedback->created_at
                && $feedback->created_at->lessThanOrEqualTo($feedback->ticket->delivered_at->copy()->addDays(7)))
            ->values();

        $intakeTickets = ServiceTicket::where('intake_by_employee_id', $emp->id)
            ->where(fn ($query) => $query->where('period_id', $period->id)->orWhereNull('period_id'))
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEndExclusive)
            ->get();
        $feedbacksByDay = $feedbacks->groupBy(fn (CustomerFeedback $feedback): string => $feedback->ticket->delivered_at->toDateString());
        $intakeByDay = $intakeTickets->groupBy(fn (ServiceTicket $ticket): string => $ticket->created_at->toDateString());
        $feedbackFollowUps = FeedbackFollowUp::with('feedback.ticket')
            ->where('assigned_employee_id', $emp->id)
            ->whereIn('customer_feedback_id', $feedbacks->pluck('id'))
            ->where('status', '!=', FeedbackFollowUp::STATUS_EXCEPTION)
            ->get();

        // CS-01: CSAT / Kepuasan Pelanggan Pelayan (%)
        $cs01 = $kpi->items->firstWhere('definition_code_snapshot', 'CS-01');
        if ($cs01 && $cs01->acceptsSystemCalculatedValue()) {
            $satisfiedCount = $feedbacks->filter(fn (CustomerFeedback $feedback): bool => (int) $feedback->rating >= 4)->count();
            $csatPercent = $feedbacks->isNotEmpty() ? round(($satisfiedCount / $feedbacks->count()) * 100, 2) : null;
            $cs01->actual_decimal = $csatPercent;
            $cs01->actual_json = [
                '_system_calculated' => true,
                'feedback_count' => $feedbacks->count(),
                'satisfied_feedbacks' => $satisfiedCount,
                'formula' => 'feedback rating 4-5 / seluruh feedback valid × 100',
            ];
            $cs01->status = 'draft';
            $cs01->save();
            $this->calculationEngine->calculateItem($cs01);
            foreach ($feedbacksByDay as $date => $dayFeedbacks) {
                $satisfied = $dayFeedbacks->filter(fn (CustomerFeedback $feedback): bool => (int) $feedback->rating >= 4)->count();
                $this->writeDailySystemValue($cs01, $date, ($satisfied / $dayFeedbacks->count()) * 100, [
                    'feedback_count' => $dayFeedbacks->count(),
                    'satisfied_feedbacks' => $satisfied,
                ]);
            }
        }

        // CS-03: Akurasi Input Order / Nota Servis (%)
        $cs03 = $kpi->items->firstWhere('definition_code_snapshot', 'CS-03');
        if ($cs03 && $cs03->acceptsSystemCalculatedValue()) {
            $validCount = $intakeTickets->filter(function ($t) {
                return ! empty($t->customer_name)
                    && ! empty($t->customer_phone)
                    && ! empty($t->device_brand)
                    && ! empty($t->device_model)
                    && ! empty($t->initial_complaint)
                    && ! empty($t->service_category)
                    && ! empty($t->service_complexity);
            })->count();
            $accuracy = $intakeTickets->isNotEmpty() ? round(($validCount / $intakeTickets->count()) * 100, 2) : null;
            $cs03->actual_decimal = $accuracy;
            $cs03->actual_json = ['_system_calculated' => true, 'intake_tickets' => $intakeTickets->count(), 'valid_tickets' => $validCount];
            $cs03->status = 'draft';
            $cs03->save();
            $this->calculationEngine->calculateItem($cs03);
            foreach ($intakeByDay as $date => $dayTickets) {
                $valid = $dayTickets->filter(fn (ServiceTicket $ticket): bool => ! empty($ticket->customer_name)
                    && ! empty($ticket->customer_phone)
                    && ! empty($ticket->device_brand)
                    && ! empty($ticket->device_model)
                    && ! empty($ticket->initial_complaint)
                    && ! empty($ticket->service_category)
                    && ! empty($ticket->service_complexity))->count();
                $this->writeDailySystemValue($cs03, $date, $dayTickets->isNotEmpty() ? ($valid / $dayTickets->count()) * 100 : null, ['valid_tickets' => $valid, 'intake_tickets' => $dayTickets->count()]);
            }
        }

        // CS-04: Follow-up Status Pelanggan oleh Pelayan (%)
        $cs04 = $kpi->items->firstWhere('definition_code_snapshot', 'CS-04');
        if ($cs04 && $cs04->acceptsSystemCalculatedValue()) {
            $ontimeFollowUp = $feedbackFollowUps->filter(fn (FeedbackFollowUp $followUp): bool => $followUp->status === FeedbackFollowUp::STATUS_COMPLETED
                && $followUp->completed_at
                && $followUp->due_at
                && $followUp->completed_at->lessThanOrEqualTo($followUp->due_at)
                && ! empty($followUp->evidence_json)
            )->count();
            $followUpRate = $feedbackFollowUps->isNotEmpty()
                ? round(($ontimeFollowUp / $feedbackFollowUps->count()) * 100, 2)
                : null;
            $cs04->actual_decimal = $followUpRate;
            $cs04->actual_json = [
                '_system_calculated' => true,
                'assigned_followups' => $feedbackFollowUps->count(),
                'ontime_followups' => $ontimeFollowUp,
                'formula' => 'follow-up selesai tepat waktu dengan evidence / follow-up yang ditugaskan × 100',
            ];
            $cs04->status = 'draft';
            $cs04->save();
            $this->calculationEngine->calculateItem($cs04);
            $followUpsByDay = $feedbackFollowUps->groupBy(fn (FeedbackFollowUp $followUp): string => $followUp->feedback->ticket->delivered_at->toDateString());
            foreach ($followUpsByDay as $date => $dayFollowUps) {
                $ontime = $dayFollowUps->filter(fn (FeedbackFollowUp $followUp): bool => $followUp->status === FeedbackFollowUp::STATUS_COMPLETED
                    && $followUp->completed_at
                    && $followUp->due_at
                    && $followUp->completed_at->lessThanOrEqualTo($followUp->due_at)
                    && ! empty($followUp->evidence_json)
                )->count();
                $this->writeDailySystemValue($cs04, $date, ($ontime / $dayFollowUps->count()) * 100, [
                    'ontime_followups' => $ontime,
                    'assigned_followups' => $dayFollowUps->count(),
                ]);
            }
        }

        return true;
    }

    protected function syncGudangKpi(EmployeeKpi $kpi, Employee $emp, KpiPeriod $period): bool
    {
        $periodStart = $period->start_date->copy()->startOfDay();
        $periodEndExclusive = $period->end_date->copy()->addDay()->startOfDay();
        $requests = SparepartRequest::where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEndExclusive)
            ->where('warehouse_employee_id', $emp->id)
            ->whereHas('ticket', fn ($query) => $query->where('branch_id', $kpi->branch_id_snapshot))
            ->whereHas('ticket', fn ($query) => $query->where(fn ($ticketQuery) => $ticketQuery->where('period_id', $period->id)->orWhereNull('period_id')))
            ->get();
        $fulfilled = $requests->where('status', 'fulfilled');

        // GUD-03: Kecepatan Penyediaan Sparepart (%)
        $gud03 = $kpi->items->firstWhere('definition_code_snapshot', 'GUD-03');
        if ($gud03) {
            $fastFulfilled = $fulfilled->filter(function ($r) {
                if (! $r->requested_at || ! $r->fulfilled_at) {
                    return false;
                }
                $elapsedMinutes = $r->requested_at->diffInMinutes($r->fulfilled_at, false);

                return $elapsedMinutes >= 0 && $elapsedMinutes <= 15; // SLA 15 menit
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
            $criticalParts = Sparepart::where('is_critical', true)->where('branch_id', $kpi->branch_id_snapshot)->get();
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
