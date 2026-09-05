<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiDailyEntry;
use App\Models\KpiPeriod;
use App\Models\User;
use App\Modules\Assessment\DailyAssessmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyAssessmentEmployeeInputTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_employee_can_save_owned_daily_input_but_cannot_write_another_employee_kpi(): void
    {
        $user = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);

        $service = app(DailyAssessmentService::class);
        $day = $service->employeeDay($user, now()->toDateString());
        $item = $day['kpi']->items->firstWhere('definition_code_snapshot', 'TEK-01');

        $this->assertSame('employee', $item->source_type_snapshot);
        $this->assertSame('draft', $day['entries']->firstWhere('employee_kpi_item_id', $item->id)->entry_status);

        $saved = $service->saveEmployeeDay($user, now()->toDateString(), [[
            'item_id' => $item->id,
            'actual_decimal' => 12,
            'note' => 'Input harian teknisi',
        ]]);
        $entry = collect($saved['entries'])->firstWhere('employee_kpi_item_id', $item->id);
        $this->assertSame(12.0, (float) $entry->employee_actual_decimal);
        $this->assertSame('draft', $entry->entry_status);
        $this->assertSame(12.0, $entry->effectiveActualDecimal());

        $otherKpi = EmployeeKpi::where('period_id', $period->id)
            ->where('employee_id', '!=', Employee::where('user_id', $user->id)->value('id'))
            ->firstOrFail();
        $this->expectException(AuthorizationException::class);
        $service->saveEmployeeDay($user, now()->toDateString(), [[
            'item_id' => $otherKpi->items()->firstOrFail()->id,
            'actual_decimal' => 10,
        ]]);
    }
}
