<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiEvidence;
use App\Models\KpiDailyEntry;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Modules\Assessment\DailyAssessmentService;
use App\Modules\Assessment\OperationalKpiSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyAssessmentWebTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_employee_can_open_daily_kpi_page_and_legacy_notification_link(): void
    {
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $kpi = EmployeeKpi::whereHas('employee', fn ($query) => $query->where('user_id', $employee->id))->firstOrFail();
        $date = $kpi->period->start_date->toDateString();

        $this->actingAs($employee)->get("/app/my-kpi/daily?date={$date}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Employee/DailyKpi')
                ->has('items', 7));

        $this->actingAs($employee)->get("/my-kpi/{$kpi->id}")
            ->assertRedirect('/app/my-kpi/daily');
    }

    public function test_supervisor_and_manager_receive_daily_navigation_and_pages(): void
    {
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();

        $supervisorPage = $this->actingAs($supervisor)->get('/app/supervisor-daily-assessments');
        $supervisorPage->assertOk()->assertInertia(fn ($page) => $page->component('Admin/DailyAssessmentQueue')->where('role', 'supervisor'));
        $supervisorLinks = collect($supervisorPage->inertiaProps('navigation'))
            ->flatMap(fn (array $group) => $group['items'] ?? [])
            ->pluck('href')->all();
        $this->assertContains('/app/supervisor-daily-assessments', $supervisorLinks);

        $managerPage = $this->actingAs($manager)->get('/app/manager-daily-assessments');
        $managerPage->assertOk()->assertInertia(fn ($page) => $page->component('Admin/DailyAssessmentQueue')->where('role', 'manager'));
        $managerLinks = collect($managerPage->inertiaProps('navigation'))
            ->flatMap(fn (array $group) => $group['items'] ?? [])
            ->pluck('href')->all();
        $this->assertContains('/app/manager-daily-assessments', $managerLinks);
    }

    public function test_supervisor_queue_exposes_read_only_state_after_review_deadline(): void
    {
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $period = EmployeeKpi::firstOrFail()->period;
        $period->update([
            'review_deadline' => now()->subMinute(),
            'approval_deadline' => now()->addDay(),
        ]);

        $date = $period->start_date->toDateString();
        $page = $this->actingAs($supervisor)->get("/app/supervisor-daily-assessments?date={$date}");

        $this->assertFalse($page->inertiaProps('canAssess'));
        $this->assertSame($period->fresh()->review_deadline->toIso8601String(), $page->inertiaProps('deadline'));
    }

    public function test_supervisor_queue_exposes_system_recap_after_employee_submit(): void
    {
        $employeeUser = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $employee = Employee::where('user_id', $employeeUser->id)->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $kpi = EmployeeKpi::where('employee_id', $employee->id)->firstOrFail();
        $item = $kpi->items()->where('definition_code_snapshot', 'TEK-01')->firstOrFail();
        $item->update(['actual_decimal' => null, 'actual_json' => null]);
        app(OperationalKpiSyncService::class)->syncPeriodOperationalData($kpi->period);
        $expectedCompleted = ServiceTicket::where('technician_employee_id', $employee->id)
            ->where('period_id', $kpi->period_id)
            ->whereIn('status', ['completed', 'delivered'])
            ->count();
        $this->assertSame($expectedCompleted, (int) $item->fresh()->actual_decimal);
        $date = ServiceTicket::where('technician_employee_id', $employee->id)
            ->whereNotNull('completed_at')
            ->orderBy('completed_at')
            ->firstOrFail()
            ->completed_at
            ->toDateString();
        $kpi->period->update([
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);
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
            'uploaded_by' => $employeeUser->id,
            'description' => 'Evidence input harian',
        ]);
        app(DailyAssessmentService::class)->saveEmployeeDay(
            $employeeUser,
            $date,
            $kpi->items()
                ->where('source_type_snapshot', 'employee')
                ->where('formula_key_snapshot', '!=', 'rubric')
                ->get()
                ->map(fn ($employeeItem): array => [
                    'item_id' => $employeeItem->id,
                    'actual_decimal' => 80,
                ])->all(),
            true
        );

        $this->actingAs($employeeUser)->get("/app/my-kpi/daily?date={$date}")->assertOk();
        $page = $this->actingAs($supervisor)->get("/app/supervisor-daily-assessments?date={$date}");
        $entry = collect($page->inertiaProps('entries'))->firstWhere('item.code', 'TEK-01');

        $this->assertNotNull($entry);
        $this->assertSame((string) $employee->id, (string) data_get($entry, 'employee.id'));
        $this->assertSame((string) $kpi->id, (string) data_get($entry, 'kpi_id'));
        $this->assertNull(data_get($entry, 'item.system_actual'));
        $expectedDailyCompleted = ServiceTicket::where('technician_employee_id', $employee->id)
            ->whereDate('completed_at', $date)
            ->count();
        $this->assertSame($expectedDailyCompleted, (int) data_get($entry, 'system_actual'));
        $this->assertSame($expectedDailyCompleted, (int) data_get($entry, 'item.system_meta.completed_tickets'));
    }

    public function test_supervisor_queue_syncs_system_values_before_showing_confirmation(): void
    {
        $employeeUser = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $employee = Employee::where('user_id', $employeeUser->id)->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $kpi = EmployeeKpi::where('employee_id', $employee->id)->firstOrFail();
        $item = $kpi->items()->where('definition_code_snapshot', 'TEK-07')->firstOrFail();
        $item->update(['actual_decimal' => null, 'actual_json' => null]);
        $kpi->update(['status' => 'submitted']);
        $date = ServiceTicket::where('technician_employee_id', $employee->id)
            ->whereNotNull('completed_at')
            ->orderBy('completed_at')
            ->firstOrFail()
            ->completed_at
            ->toDateString();

        $kpi->period->update([
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);

        $page = $this->actingAs($supervisor)->get("/app/supervisor-daily-assessments?date={$date}");
        $entry = collect($page->inertiaProps('entries'))->firstWhere('item.code', 'TEK-07');

        $this->assertNotNull($entry);
        $this->assertNotNull(data_get($entry, 'item.system_actual'));

        $this->actingAs($supervisor)
            ->post("/app/supervisor-daily-assessments/{$entry['id']}/assess", ['decision' => 'approved'])
            ->assertRedirect("/app/supervisor-daily-assessments?date={$date}");

        $this->assertSame('approved', KpiDailyEntry::findOrFail($entry['id'])->supervisor_status);
    }
}
