<?php

namespace Tests\Feature;

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

    public function test_employee_is_read_only_and_supervisor_records_daily_input(): void
    {
        $user = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
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
        $this->assertSame('submitted', $day['entries']->firstWhere('employee_kpi_item_id', $item->id)->entry_status);

        try {
            $service->saveEmployeeDay($user, now()->toDateString(), [[
                'item_id' => $item->id,
                'actual_decimal' => 12,
            ]]);
            $this->fail('Karyawan tidak boleh mengisi KPI harian.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('Supervisor', $exception->getMessage());
        }

        $queue = $service->supervisorQueue($supervisor, now()->toDateString());
        $entry = $queue->firstWhere('item.definition_code_snapshot', 'TEK-01');
        $updated = $service->assessSupervisor($supervisor, $entry->id, 'approved', 12);

        $this->assertSame(12.0, (float) $updated->supervisor_actual_decimal);
        $this->assertSame('approved', $updated->supervisor_status);
    }
}
