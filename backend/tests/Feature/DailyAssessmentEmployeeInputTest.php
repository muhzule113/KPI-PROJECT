<?php

namespace Tests\Feature;

use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Modules\Assessment\DailyAssessmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyAssessmentEmployeeInputTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_employee_is_read_only_and_supervisor_only_confirms_official_value(): void
    {
        $user = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);
        $date = ServiceTicket::whereNotNull('completed_at')->orderBy('completed_at')->firstOrFail()->completed_at->toDateString();

        $service = app(DailyAssessmentService::class);
        $day = $service->employeeDay($user, $date);
        $item = $day['kpi']->items->firstWhere('definition_code_snapshot', 'TEK-01');

        $this->assertSame('system', $item->source_type_snapshot);
        $this->assertSame('submitted', $day['entries']->firstWhere('employee_kpi_item_id', $item->id)->entry_status);

        try {
            $service->saveEmployeeDay($user, $date, [[
                'item_id' => $item->id,
                'actual_decimal' => 12,
            ]]);
            $this->fail('Karyawan tidak boleh mengisi KPI harian.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('Supervisor', $exception->getMessage());
        }

        $queue = $service->supervisorQueue($supervisor, $date);
        $entry = $queue->firstWhere('item.definition_code_snapshot', 'TEK-01');
        $officialValue = (float) $item->actual_decimal;
        $updated = $service->assessSupervisor($supervisor, $entry->id, 'approved');

        $this->assertNull($updated->supervisor_actual_decimal);
        $this->assertSame('approved', $updated->supervisor_status);
        $this->assertSame($officialValue, (float) $item->fresh()->actual_decimal);
    }
}
