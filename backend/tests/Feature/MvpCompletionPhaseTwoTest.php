<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiCorrectionRequest;
use App\Models\KpiPeriod;
use App\Models\Position;
use App\Models\ReportSubmission;
use App\Models\User;
use App\Modules\Organization\EmployeePlacementService;
use App\Modules\Period\PeriodService;
use App\Modules\Reporting\RankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MvpCompletionPhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_placement_is_authoritative_non_overlapping_and_updates_employee_cache(): void
    {
        $employee = Employee::where('employee_number', 'EMP-003')->firstOrFail();
        $branch = Branch::where('code', 'CAB-02')->firstOrFail();
        $position = Position::where('code', 'POS-KSR')->firstOrFail();
        $service = app(EmployeePlacementService::class);

        $placement = $service->place($employee, $position->id, $branch->id, $employee->supervisor_id, '2026-09-15');
        $employee->refresh();
        $this->assertSame($placement->id, $employee->placements()->latest('effective_from')->firstOrFail()->id);
        $this->assertSame($position->id, $employee->position_id);
        $this->assertSame($branch->id, $employee->branch_id);
        $this->assertSame('2026-09-14', $employee->placements()->where('id', '!=', $placement->id)->latest('effective_from')->firstOrFail()->effective_until->toDateString());

        $this->expectException(RuntimeException::class);
        $service->place($employee, $position->id, $branch->id, null, '2026-09-10');
    }

    public function test_ready_snapshots_roster_and_open_is_strict_and_idempotent(): void
    {
        $branch = Branch::where('code', 'CAB-01')->firstOrFail();
        $otherBranch = Branch::where('code', 'CAB-02')->firstOrFail();
        $admin = User::role('kpi_admin')->firstOrFail();
        $technicianPosition = Position::where('code', 'POS-TEK')->firstOrFail();
        $supervisor = Employee::where('employee_number', 'EMP-002')->firstOrFail();
        $partialUser = User::factory()->create(['email' => 'pegawai.parsial@example.test']);
        $partialUser->assignRole('employee');
        $partialEmployee = Employee::create([
            'user_id' => $partialUser->id,
            'employee_number' => 'EMP-PARTIAL',
            'name' => 'Pegawai Parsial',
            'email' => $partialUser->email,
            'position_id' => $technicianPosition->id,
            'branch_id' => $branch->id,
            'supervisor_id' => $supervisor->id,
            'joined_at' => '2026-09-15',
            'status' => 'active',
        ]);
        app(EmployeePlacementService::class)->place(
            $partialEmployee,
            $technicianPosition->id,
            $branch->id,
            $supervisor->id,
            '2026-09-15',
        );
        $period = KpiPeriod::create([
            'name' => 'September 2026', 'year' => 2026, 'month' => 9,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
            'submission_deadline' => '2026-09-25 18:00:00',
            'review_deadline' => '2026-09-28 18:00:00',
            'approval_deadline' => '2026-09-30 18:00:00',
            'status' => 'DRAFT', 'created_by' => $admin->id,
        ]);
        $period->branches()->attach($branch->id);
        $service = app(PeriodService::class);

        try {
            $service->openPeriod($period);
            $this->fail('DRAFT tidak boleh langsung OPEN.');
        } catch (RuntimeException) {
            $this->assertSame('DRAFT', $period->fresh()->status);
        }

        $service->markReady($period);
        $period->refresh();
        $this->assertSame('READY', $period->status);
        $this->assertGreaterThan(0, $period->employeeKpis()->count());
        $this->assertGreaterThan(0, ReportSubmission::where('period_id', $period->id)->count());
        $this->assertSame('partial', $period->employeeKpis()->where('employee_id', $partialEmployee->id)->firstOrFail()->eligibility);

        $snapshotEmployee = Employee::where('employee_number', 'EMP-003')->firstOrFail();
        $snapshot = $period->employeeKpis()->where('employee_id', $snapshotEmployee->id)->firstOrFail();
        $snapshotBranch = $snapshot->branch_id_snapshot;
        app(EmployeePlacementService::class)->place(
            $snapshotEmployee,
            $snapshotEmployee->position_id,
            $otherBranch->id,
            $snapshotEmployee->supervisor_id,
            '2026-09-15',
        );
        $service->openPeriod($period);
        $count = $period->employeeKpis()->count();
        $service->openPeriod($period->fresh());
        $this->assertSame($count, $period->employeeKpis()->count());
        $this->assertSame($snapshotBranch, $snapshot->fresh()->branch_id_snapshot);
        $this->assertNotEmpty($snapshot->fresh()->rating_bands_snapshot);

        $this->assertSame($period->id, KpiPeriod::resolveOpen('2026-09-07', $branch->id)?->id);
    }

    public function test_final_ranking_uses_locked_full_snapshots_shared_rank_and_pending_correction(): void
    {
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $kpis = EmployeeKpi::where('period_id', $period->id)->take(4)->get();
        foreach ($kpis as $index => $kpi) {
            $kpi->update([
                'status' => 'locked', 'position_code_snapshot' => 'POS-TEST',
                'eligibility' => $index === 3 ? 'partial' : 'full', 'final_score' => $index === 3 ? 100 : 90,
            ]);
            $kpi->items->sortByDesc('weight_snapshot')->firstOrFail()->update([
                'achievement_percentage' => $index < 2 ? 80 : 70,
            ]);
        }
        KpiCorrectionRequest::create([
            'employee_kpi_id' => $kpis[0]->id, 'requested_by' => User::where('email', 'manager@toko.com')->value('id'),
            'reason' => 'Verifikasi ranking', 'before_json' => [], 'after_json' => [], 'status' => 'pending',
        ]);

        $rows = app(RankingService::class)->forPeriod($period)['POS-TEST'];

        $this->assertCount(3, $rows);
        $this->assertSame([1, 1, 3], array_column($rows, 'rank'));
        $this->assertTrue($rows[0]['correction_in_progress'] || $rows[1]['correction_in_progress']);
    }

    public function test_employee_admin_update_creates_a_new_authoritative_placement(): void
    {
        $employee = Employee::where('employee_number', 'EMP-003')->firstOrFail();
        $branch = Branch::where('code', 'CAB-02')->firstOrFail();
        $payload = $employee->only(['employee_number', 'user_id', 'name', 'email', 'phone', 'position_id', 'supervisor_id', 'status']);
        $payload += ['branch_id' => $branch->id, 'joined_at' => $employee->joined_at->toDateString(), 'placement_effective_from' => '2026-08-10'];

        $this->actingAs(User::where('email', 'admin@kpi.com')->firstOrFail(), 'web')
            ->put("/app/employees/{$employee->id}", $payload)->assertRedirect('/app/employees');

        $this->assertSame($branch->id, $employee->fresh()->branch_id);
        $this->assertDatabaseHas('employee_placements', [
            'employee_id' => $employee->id, 'branch_id' => $branch->id, 'effective_from' => '2026-08-10',
        ]);
        $this->assertSame('2026-08-09', $employee->placements()->where('branch_id', '!=', $branch->id)->firstOrFail()->effective_until->toDateString());
    }
}
