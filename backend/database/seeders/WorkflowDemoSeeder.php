<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\KpiDefinition;
use App\Models\KpiPeriod;
use App\Models\KpiRatingScheme;
use App\Models\KpiTemplate;
use App\Models\KpiTemplateItem;
use App\Models\KpiTemplateVersion;
use App\Models\Position;
use App\Models\ServiceTicket;
use App\Models\Sparepart;
use App\Models\User;
use App\Modules\Approval\ApprovalService;
use App\Modules\Assessment\DailyAssessmentService;
use App\Modules\Period\PeriodService;
use App\Modules\Review\ReviewService;
use App\Modules\Service\ServiceTicketService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Contoh satu hari dengan template ringkas; katalog utama tetap dipakai periode berjalan. */
class WorkflowDemoSeeder extends Seeder
{
    public function run(): void
    {
        $day = now()->subMonthsNoOverflow(2)->startOfMonth()->nextWeekday()->setTime(9, 0);
        if (KpiPeriod::where('year', $day->year)->where('month', $day->month)->exists()) {
            return;
        }
        $clock = Carbon::getTestNow();
        try {
            Carbon::setTestNow($day);
            DB::transaction(fn () => $this->seedCycle($day));
        } finally {
            Carbon::setTestNow($clock);
        }
    }

    private function seedCycle(Carbon $day): void
    {
        $admin = User::where('email', 'kpi_admin@kpi.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $originalTemplates = KpiTemplate::where('is_active', true)->pluck('id');
        KpiTemplate::whereIn('id', $originalTemplates)->update(['is_active' => false]);
        $demoTemplates = [];
        $indicators = [
            'POS-TEK' => ['TEK-01' => 100],
            'POS-CS' => ['CS-06' => 100],
            'POS-ADM' => ['ADM-05' => 100],
            'POS-KSR' => ['KSR-06' => 100],
            'POS-GUD' => ['GUD-07' => 100],
            'POS-SPV' => ['SUP-01' => 50, 'SUP-06' => 50],
        ];
        foreach ($indicators as $positionCode => $weights) {
            $template = KpiTemplate::create(['code' => 'DEMO-'.$positionCode, 'name' => 'Demo alur '.$positionCode, 'position_id' => Position::where('code', $positionCode)->value('id'), 'is_active' => true]);
            $demoTemplates[] = $template->id;
            $version = KpiTemplateVersion::create(['kpi_template_id' => $template->id, 'version_number' => 1, 'status' => 'active', 'total_weight' => 100, 'rating_scheme_id' => KpiRatingScheme::where('is_default', true)->value('id'), 'effective_from' => $day->toDateString(), 'activated_by' => $admin->id, 'activated_at' => now()]);
            foreach ($weights as $code => $weight) {
                $definition = KpiDefinition::where('code', $code)->firstOrFail();
                KpiTemplateItem::create(['template_version_id' => $version->id, 'kpi_definition_id' => $definition->id, 'weight' => $weight, 'target_value' => $code === 'TEK-01' ? 1 : 100, 'target_unit' => $code === 'TEK-01' ? 'unit' : '%', 'formula_key' => 'higher_is_better', 'source_type' => $definition->source_type, 'evidence_required' => false, 'sort_order' => 1]);
            }
        }
        $period = KpiPeriod::create(['name' => 'Demo siklus lengkap '.$day->translatedFormat('F Y'), 'year' => $day->year, 'month' => $day->month, 'start_date' => $day->toDateString(), 'end_date' => $day->toDateString(), 'submission_deadline' => $day->copy()->addDay(), 'review_deadline' => $day->copy()->addDays(2), 'approval_deadline' => $day->copy()->addDays(3), 'status' => 'DRAFT', 'created_by' => $admin->id]);
        $period->branches()->attach(Branch::where('code', 'CAB-01')->value('id'));
        $periodService = app(PeriodService::class);
        $periodService->markReady($period);
        $periodService->openPeriod($period->fresh());
        KpiTemplate::whereIn('id', $demoTemplates)->update(['is_active' => false]);
        KpiTemplate::whereIn('id', $originalTemplates)->update(['is_active' => true]);

        $this->completeTicket();
        $daily = app(DailyAssessmentService::class);
        $daily->preparePeriod($period, $day->toDateString());
        foreach ($period->employeeKpis()->with('items.dailyEntries')->get()->reject->isSupervisorKpi() as $kpi) {
            foreach ($kpi->items as $item) {
                foreach ($item->dailyEntries as $entry) {
                    $daily->assessSupervisor(
                        $supervisor,
                        $entry->id,
                        'approved',
                        actualDecimal: ! $item->isAttendanceIndicator() && ! $item->isSystemSourced()
                            ? (float) ($entry->system_actual_decimal ?? $item->actual_decimal ?? 100)
                            : null,
                        actualJson: $item->isAttendanceIndicator() ? ['attendance_status' => Attendance::STATUS_PRESENT] : null,
                    );
                }
            }
            app(ReviewService::class)->forwardToManager($kpi->fresh(), reviewerId: $supervisor->id);
            app(ApprovalService::class)->approve($kpi->fresh(), approverId: $manager->id);
        }
        $daily->preparePeriod($period, $day->toDateString());
        $supervisorKpi = $period->employeeKpis()->where('employee_id', $supervisor->employee->id)->firstOrFail();
        foreach ($supervisorKpi->items()->with('dailyEntries')->get() as $item) {
            foreach ($item->dailyEntries as $entry) {
                $daily->assessManager(
                    $manager,
                    $entry->id,
                    'approved',
                    actualDecimal: $item->isSystemSourced() ? null : 95,
                    actualJson: $item->isManualRated() ? ['rating_code' => 'VERY_GOOD'] : null,
                );
            }
        }
        app(ApprovalService::class)->approve($supervisorKpi->fresh(), approverId: $manager->id);
        $periodService->closeSubmission($period->fresh());
        $periodService->startReview($period->fresh());
        $periodService->startApproval($period->fresh());
        $periodService->publishPeriod($period->fresh());
        $periodService->lockPeriod($period->fresh());
    }

    private function completeTicket(): void
    {
        $service = app(ServiceTicketService::class);
        $cs = User::where('email', 'cs@toko.com')->firstOrFail();
        $tech = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $cashier = User::where('email', 'kasir@toko.com')->firstOrFail();
        $warehouse = User::where('email', 'gudang@toko.com')->firstOrFail();
        $result = $service->store($cs, ['customer_name' => 'Pelanggan Demo Siklus', 'customer_phone' => '081200000001', 'device_brand' => 'Samsung', 'device_model' => 'A54', 'physical_condition' => 'Layar retak, unit dapat menyala', 'initial_complaint' => 'Penggantian layar', 'customer_needs' => 'Perbaikan layar']);
        $ticket = ServiceTicket::findOrFail($result['data']['id']);
        $version = fn () => ['row_version' => $ticket->fresh()->row_version];
        $service->assignTechnician($tech, $version(), $ticket->id);
        $service->updateProgress($tech, $version() + ['status' => 'diagnosing', 'diagnosis_notes' => 'Panel layar rusak'], $ticket->id);
        $service->recordEstimatedCost($cashier, $version() + ['estimated_cost' => 850000, 'note' => 'Layar dan jasa'], $ticket->id);
        $service->recordConsent($cs, $version() + ['consent_status' => 'approved', 'note' => 'Pelanggan menyetujui biaya'], $ticket->id);
        $service->requestSparepart($tech, $version() + ['service_ticket_id' => $ticket->id, 'sparepart_id' => Sparepart::where('code', 'PRT-LCD-SMA54')->value('id'), 'quantity' => 1]);
        $requestId = (string) $ticket->sparepartRequests()->firstOrFail()->id;
        $service->fulfillSparepart($warehouse, $version(), $requestId);
        $service->confirmSparepart($tech, $version(), $requestId);
        $service->updateProgress($tech, $version() + ['status' => 'in_progress', 'action_notes' => 'Penggantian panel'], $ticket->id);
        $service->updateProgress($tech, $version() + ['status' => 'qc_ready'], $ticket->id);
        $service->complete($tech, $version() + ['result_status' => 'success', 'diagnosis_notes' => 'Panel layar rusak', 'action_notes' => 'Panel diganti dan fungsi diuji', 'qc_checklist' => array_fill_keys(ServiceTicket::REQUIRED_QC_KEYS, true), 'technical_evidence' => [['type' => 'service_note', 'reference' => 'DEMO-QC-LAYAR']]], $ticket->id);
        $service->recordFinalCost($cashier, $version() + ['final_cost' => 850000, 'note' => 'Sesuai estimasi'], $ticket->id);
        $service->recordPayment($cashier, $version() + ['paid_amount' => 850000, 'payment_method' => 'cash', 'note' => 'Lunas'], $ticket->id);
        $service->deliver($cs, $version() + ['recipient_type' => 'customer', 'recipient_name' => 'Pelanggan Demo Siklus'], $ticket->id);
    }
}
