<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\KpiPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiPeriodManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_new_period_is_persisted_and_cannot_be_removed_by_generic_crud(): void
    {
        $admin = User::role('kpi_admin')->firstOrFail();
        $branch = Branch::where('code', 'CAB-01')->firstOrFail();

        $this->actingAs($admin)
            ->post('/app/kpi-periods', [
                'name' => 'Periode September 2026',
                'year' => 2026,
                'month' => 9,
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-30',
                'submission_deadline' => '2026-09-28 23:59:59',
                'review_deadline' => '2026-09-29 23:59:59',
                'approval_deadline' => '2026-09-30 23:59:59',
                'branches' => [$branch->id],
            ])
            ->assertRedirect('/app/kpi-periods');

        $this->assertDatabaseHas('kpi_periods', [
            'name' => 'Periode September 2026',
            'year' => 2026,
            'month' => 9,
            'status' => 'DRAFT',
        ]);

        $periodId = (int) KpiPeriod::query()
            ->where('name', 'Periode September 2026')
            ->value('id');
        $indexResponse = $this->actingAs($admin)->get('/app/kpi-periods');

        $indexResponse->assertOk();
        $this->assertFalse($indexResponse->inertiaProps('resource.can_delete'));

        $this->actingAs($admin)
            ->delete("/app/kpi-periods/{$periodId}")
            ->assertForbidden();

        $this->assertDatabaseHas('kpi_periods', ['id' => $periodId]);
    }
}
