<?php

namespace Tests\Feature;

use App\Models\EmployeeKpi;
use App\Models\Employee;
use App\Models\ServiceTicket;
use App\Models\User;
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

    public function test_supervisor_queue_exposes_system_recap_for_the_employee(): void
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
        $date = $kpi->period->start_date->toDateString();

        $this->actingAs($employeeUser)->get("/app/my-kpi/daily?date={$date}")->assertOk();
        $page = $this->actingAs($supervisor)->get("/app/supervisor-daily-assessments?date={$date}");
        $entry = collect($page->inertiaProps('entries'))->firstWhere('item.code', 'TEK-01');

        $this->assertNotNull($entry);
        $this->assertSame((string) $employee->id, (string) data_get($entry, 'employee.id'));
        $this->assertSame((string) $kpi->id, (string) data_get($entry, 'kpi_id'));
        $this->assertSame((float) $expectedCompleted, (float) data_get($entry, 'item.system_actual'));
        $this->assertSame($expectedCompleted, (int) data_get($entry, 'item.system_meta.completed_tickets'));
    }
}
