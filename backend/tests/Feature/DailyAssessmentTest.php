<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\KpiDailyEntry;
use App\Models\KpiEvidence;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\SystemNotification;
use App\Models\User;
use App\Modules\Assessment\DailyAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected DailyAssessmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DailyAssessmentService::class);
    }

    public function test_employee_can_view_and_save_owned_daily_kpi(): void
    {
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);

        $date = now()->toDateString();
        $day = $this->service->employeeDay($employee, $date);

        $this->assertNotEmpty($day['entries']);
        $employeeItem = $day['kpi']->items->firstWhere('definition_code_snapshot', 'TEK-01');
        $entry = $day['entries']->firstWhere('employee_kpi_item_id', $employeeItem->id);
        $this->assertSame('draft', $entry->entry_status);

        $saved = $this->service->saveEmployeeDay($employee, $date, [[
            'item_id' => $employeeItem->id,
            'actual_decimal' => 12,
            'note' => 'Input harian teknisi',
        ]]);
        $savedEntry = $saved['entries']->firstWhere('employee_kpi_item_id', $employeeItem->id);
        $this->assertSame(12.0, (float) $savedEntry->employee_actual_decimal);
        $this->assertSame('draft', $savedEntry->entry_status);
    }

    public function test_supervisor_and_manager_can_complete_daily_review_after_employee_input(): void
    {
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);

        $date = ServiceTicket::whereNotNull('completed_at')
            ->orderBy('completed_at')
            ->firstOrFail()
            ->completed_at
            ->toDateString();
        $employeeRecord = Employee::where('user_id', $employee->id)->firstOrFail();
        $kpi = EmployeeKpi::where('period_id', $period->id)
            ->where('employee_id', $employeeRecord->id)
            ->firstOrFail();

        $evidenceItem = $kpi->items()->where('evidence_req_snapshot', true)->firstOrFail();
        KpiEvidence::create([
            'employee_kpi_item_id' => $evidenceItem->id,
            'file_path' => 'tests/daily-input.pdf',
            'file_name' => 'daily-input.pdf',
            'file_size' => 100,
            'mime_type' => 'application/pdf',
            'sha256_hash' => hash('sha256', 'daily-input'),
            'scan_status' => 'clean',
            'scanned_at' => now(),
            'scan_note' => 'Test',
            'uploaded_by' => $employee->id,
            'description' => 'Evidence input harian',
        ]);
        $employeeItems = $kpi->items()
            ->where('source_type_snapshot', 'employee')
            ->where('formula_key_snapshot', '!=', 'rubric')
            ->get();
        $this->service->saveEmployeeDay(
            $employee,
            $date,
            $employeeItems->map(fn (EmployeeKpiItem $item): array => [
                'item_id' => $item->id,
                'actual_decimal' => 80,
            ])->all(),
            true
        );
        $queue = $this->service->supervisorQueue($supervisor, $date);
        $employeeQueue = $queue->filter(fn (KpiDailyEntry $entry) => (string) $entry->item->employee_kpi_id === (string) $kpi->id)->values();
        $this->assertCount($kpi->items()->count(), $employeeQueue);

        foreach ($employeeQueue as $entry) {
            $answers = $entry->item->formula_key_snapshot === 'rubric'
                ? collect($entry->item->rubric_snapshot['criteria'] ?? [])->map(fn (array $criterion) => [
                    'criterion_id' => $criterion['id'],
                    'is_fulfilled' => true,
                ])->all()
                : null;

            $this->service->assessSupervisor(
                user: $supervisor,
                entryId: $entry->id,
                decision: 'approved',
                actualDecimal: $answers || $entry->item->isSystemSourced() ? null : 80,
                answers: $answers
            );
        }

        $managerQueue = $this->service->managerQueue($manager, $date);
        $this->assertCount($kpi->items()->count(), $managerQueue);
        foreach ($managerQueue as $entry) {
            $answers = $entry->item->formula_key_snapshot === 'rubric'
                ? collect($entry->item->rubric_snapshot['criteria'] ?? [])->map(fn (array $criterion) => [
                    'criterion_id' => $criterion['id'],
                    'is_fulfilled' => true,
                ])->all()
                : null;

            $this->service->assessManager(
                user: $manager,
                entryId: $entry->id,
                decision: 'approved',
                actualDecimal: $answers || $entry->item->isSystemSourced() ? null : 80,
                answers: $answers
            );
        }

        $serviceItem = $kpi->items()->where('definition_code_snapshot', 'TEK-01')->firstOrFail()->fresh();
        $this->assertSame(80.0, (float) $serviceItem->actual_decimal);
        $this->assertSame('sum', $serviceItem->actual_json['aggregation']);
    }

    public function test_manager_cannot_assess_before_supervisor_and_supervisor_cannot_assess_as_manager(): void
    {
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);

        $date = now()->toDateString();
        $this->service->employeeDay($employee, $date);
        $entry = KpiDailyEntry::whereDate('entry_date', $date)->firstOrFail();

        try {
            $this->service->assessManager($manager, $entry->id, 'approved', 45);
            $this->fail('Manager seharusnya menunggu persetujuan Supervisor.');
        } catch (\Exception $exception) {
            $this->assertSame('Entri harian belum siap untuk direview.', $exception->getMessage());
        }

        try {
            $this->service->assessManager($supervisor, $entry->id, 'approved', 45);
            $this->fail('Supervisor tidak boleh menggunakan alur penilaian Manager.');
        } catch (\Exception $exception) {
            $this->assertSame('Peran Anda tidak dapat melakukan tindakan ini.', $exception->getMessage());
        }
    }

    public function test_system_creates_supervisor_daily_queue_and_forwards_only_a_complete_review_to_manager(): void
    {
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);

        $date = ServiceTicket::whereNotNull('completed_at')
            ->orderBy('completed_at')
            ->firstOrFail()
            ->completed_at
            ->toDateString();
        $employeeRecord = Employee::where('user_id', $employee->id)->firstOrFail();
        $kpi = EmployeeKpi::where('period_id', $period->id)
            ->where('employee_id', $employeeRecord->id)
            ->firstOrFail();
        $supervisorRecord = Employee::where('user_id', $supervisor->id)->firstOrFail();
        $assignedKpis = EmployeeKpi::with('items')
            ->where('period_id', $period->id)
            ->where('supervisor_id_snapshot', $supervisorRecord->id)
            ->get();
        $evidenceItem = $kpi->items()->where('evidence_req_snapshot', true)->firstOrFail();
        KpiEvidence::create([
            'employee_kpi_item_id' => $evidenceItem->id,
            'file_path' => 'tests/daily-input.pdf',
            'file_name' => 'daily-input.pdf',
            'file_size' => 100,
            'mime_type' => 'application/pdf',
            'sha256_hash' => hash('sha256', 'daily-input'),
            'scan_status' => 'clean',
            'scanned_at' => now(),
            'scan_note' => 'Test',
            'uploaded_by' => $employee->id,
            'description' => 'Evidence input harian',
        ]);
        $employeeItems = $kpi->items()
            ->where('source_type_snapshot', 'employee')
            ->where('formula_key_snapshot', '!=', 'rubric')
            ->get();
        $this->service->saveEmployeeDay(
            $employee,
            $date,
            $employeeItems->map(fn (EmployeeKpiItem $item): array => [
                'item_id' => $item->id,
                'actual_decimal' => 80,
            ])->all(),
            true
        );
        $expectedQueueEntries = $assignedKpis->sum(
            fn (EmployeeKpi $assignedKpi): int => $assignedKpi->items
                ->filter(fn (EmployeeKpiItem $item): bool => strtolower((string) $item->source_type_snapshot) !== 'employee'
                    || (string) $assignedKpi->employee_id === (string) $employeeRecord->id)
                ->count()
        );

        $queue = $this->service->supervisorQueue($supervisor, $date);
        $employeeQueue = $queue->filter(fn (KpiDailyEntry $entry) => (string) $entry->item->employee_kpi_id === (string) $kpi->id)->values();
        $this->assertCount($expectedQueueEntries, $queue);
        $this->assertSame(
            $expectedQueueEntries,
            KpiDailyEntry::whereDate('entry_date', $date)->where('entry_status', 'submitted')->count()
        );
        $this->assertSame($assignedKpis->count(), SystemNotification::where('type', 'daily_kpi_submitted')->count());
        $this->assertCount($kpi->items()->count(), $employeeQueue);

        foreach ($employeeQueue as $index => $entry) {
            $answers = $entry->item->formula_key_snapshot === 'rubric'
                ? collect($entry->item->rubric_snapshot['criteria'] ?? [])->map(fn (array $criterion) => [
                    'criterion_id' => $criterion['id'],
                    'is_fulfilled' => true,
                ])->all()
                : null;

            $this->service->assessSupervisor(
                user: $supervisor,
                entryId: $entry->id,
                decision: 'approved',
                actualDecimal: $answers ? null : 80,
                answers: $answers
            );

            $managerQueue = $this->service->managerQueue($manager, $date);
            if ($index < $employeeQueue->count() - 1) {
                $this->assertCount(0, $managerQueue);
            }
        }

        $this->assertCount($kpi->items()->count(), $this->service->managerQueue($manager, $date));
        $this->assertSame(1, SystemNotification::where('type', 'daily_kpi_reviewed')->count());
    }

    public function test_system_indicator_uses_daily_value_and_aggregates_after_manager_confirmation(): void
    {
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);

        $date = ServiceTicket::whereNotNull('completed_at')
            ->orderBy('completed_at')
            ->firstOrFail()
            ->completed_at
            ->toDateString();
        $employeeRecord = Employee::where('user_id', $employee->id)->firstOrFail();
        $kpi = EmployeeKpi::where('period_id', $period->id)
            ->where('employee_id', $employeeRecord->id)
            ->firstOrFail();
        $item = $kpi->items()->where('definition_code_snapshot', 'TEK-07')->firstOrFail();
        $item->update(['actual_decimal' => 10, 'actual_json' => ['seed_value' => 10]]);

        $this->service->employeeDay($employee, $date);
        $entry = KpiDailyEntry::whereDate('entry_date', $date)
            ->where('employee_kpi_item_id', $item->id)
            ->firstOrFail();
        $entry->update([
            'system_actual_decimal' => 10,
            'system_actual_json' => ['source' => 'test'],
            'entry_status' => 'submitted',
        ]);

        $supervisorEntry = $this->service->assessSupervisor($supervisor, $entry->id, 'approved');
        $this->assertSame('approved', $supervisorEntry->supervisor_status);
        $this->assertNull($supervisorEntry->supervisor_actual_decimal);

        $managerEntry = $this->service->assessManager($manager, $entry->id, 'approved');
        $this->assertSame('approved', $managerEntry->manager_status);
        $this->assertNull($managerEntry->manager_actual_decimal);
        $this->assertSame(10.0, (float) $item->fresh()->actual_decimal);
        $this->assertTrue((bool) data_get($item->fresh()->actual_json, '_daily_aggregate', false));
    }
}
