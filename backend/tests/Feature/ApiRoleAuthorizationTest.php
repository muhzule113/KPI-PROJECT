<?php

namespace Tests\Feature;

use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_employee_cannot_access_manager_or_supervisor_endpoints(): void
    {
        $userTek = User::where('email', 'teknisi@toko.com')->first();
        $this->assertNotNull($userTek);

        $this->actingAs($userTek, 'sanctum')->getJson('/api/v1/manager/queue')->assertForbidden();
        $this->actingAs($userTek, 'sanctum')->getJson('/api/v1/supervisor/queue')->assertForbidden();
        $this->actingAs($userTek, 'sanctum')->postJson('/api/v1/manager/approval/1/approve')->assertForbidden();
    }

    public function test_manager_can_access_manager_endpoints_but_not_supervisor(): void
    {
        $userMgr = User::where('email', 'manager@toko.com')->first();
        $this->assertNotNull($userMgr);

        $this->actingAs($userMgr, 'sanctum')->getJson('/api/v1/manager/queue')->assertOk();
        $this->actingAs($userMgr, 'sanctum')->getJson('/api/v1/supervisor/queue')->assertForbidden();
    }

    public function test_supervisor_can_access_supervisor_endpoints_but_not_manager(): void
    {
        $userSpv = User::where('email', 'supervisor@toko.com')->first();
        $this->assertNotNull($userSpv);

        $this->actingAs($userSpv, 'sanctum')->getJson('/api/v1/supervisor/queue')->assertOk();
        $this->actingAs($userSpv, 'sanctum')->getJson('/api/v1/manager/queue')->assertForbidden();
    }

    public function test_daily_assessment_api_enforces_roles(): void
    {
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $date = KpiPeriod::where('status', 'OPEN')->firstOrFail()->start_date->toDateString();

        $this->actingAs($employee, 'sanctum')->getJson('/api/v1/manager/daily')->assertForbidden();
        $this->actingAs($supervisor, 'sanctum')->getJson("/api/v1/supervisor/daily?date={$date}")->assertOk();
        $this->actingAs($supervisor, 'sanctum')->getJson("/api/v1/manager/daily?date={$date}")->assertForbidden();
        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/manager/daily?date={$date}")->assertOk();
    }

    public function test_employee_can_read_but_cannot_write_own_daily_kpi(): void
    {
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $kpi = EmployeeKpi::where('employee_id', $employee->employee->id)
            ->whereHas('period', fn ($query) => $query->where('status', 'OPEN'))
            ->firstOrFail();
        $period = $kpi->period;
        $period->update(['submission_deadline' => now()->addDay()]);
        $item = $kpi->items()
            ->where('source_type_snapshot', 'employee')
            ->firstOrFail();
        $date = $period->start_date->toDateString();

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/v1/my-kpi/items/{$item->id}")
            ->assertOk();
        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/v1/my-kpi/daily', [
                'date' => $date,
                'items' => [[
                    'item_id' => $item->id,
                    'actual_decimal' => 100,
                ]],
                'submit' => false,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/v1/my-kpi/submit')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }
}
