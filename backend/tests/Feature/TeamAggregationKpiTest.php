<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Modules\Assessment\TeamAggregationKpiSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamAggregationKpiTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_team_aggregation_sync_sets_supervisor_kpis(): void
    {
        $empSpv = Employee::where('email', 'supervisor@toko.com')->first();
        $empTek = Employee::where('email', 'teknisi@toko.com')->first();
        $empCs = Employee::where('email', 'cs@toko.com')->first();
        $period = KpiPeriod::where('status', 'OPEN')->first();

        $this->assertNotNull($empSpv);
        $this->assertNotNull($empTek);

        // SUP-01: kasih final_score seragam ke SEMUA KPI anggota tim, lalu teknisi=90 & CS=85
        $teamKpis = EmployeeKpi::where('period_id', $period->id)
            ->whereHas('employee', fn ($q) => $q->where('supervisor_id', $empSpv->id))
            ->get();
        $this->assertGreaterThanOrEqual(2, $teamKpis->count());

        foreach ($teamKpis as $tk) {
            $tk->update(['final_score' => 80.0]);
        }
        $kpiTek = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $empTek->id)->first();
        $kpiCs = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $empCs->id)->first();
        $kpiTek->update(['final_score' => 90.0]);
        $kpiCs->update(['final_score' => 85.0]);
        $expectedAvgScore = round($teamKpis->fresh()->avg('final_score'), 2);

        // SUP-02: achievement item pertama tiap KPI tim = 70, teknisi = 90 → rata-rata dihitung dinamis
        foreach ($teamKpis as $tk) {
            $tk->items->first()->update(['achievement_percentage' => 70.0]);
        }
        $kpiTek->items->first()->update(['achievement_percentage' => 90.0]);
        $expectedAvgAchievement = round(
            $teamKpis->fresh()->flatMap(fn ($k) => $k->items->pluck('achievement_percentage'))->avg(),
            2
        );
        $pending = app(TeamAggregationKpiSyncService::class)->syncPeriodTeamAggregation($period);
        $this->assertSame(0, $pending['updated_items']);
        foreach ($teamKpis as $tk) {
            $tk->update(['status' => 'approved', 'approved_at' => now()]);
        }

        // SUP-03: absensi — semua anggota tim penuh, CS hanya setengah hari kerja
        $attendanceEnd = $period->end_date->copy()->min(now()->startOfDay());
        $workingDays = 0;
        $date = $period->start_date->copy();
        while ($date->lte($attendanceEnd)) {
            if (! $date->isWeekend()) {
                $workingDays++;
            }
            $date->addDay();
        }
        $this->assertGreaterThan(0, $workingDays);

        $day = $period->start_date->copy();
        while ($day->lte($attendanceEnd)) {
            if (! $day->isWeekend()) {
                Attendance::create([
                    'employee_id' => $empTek->id,
                    'attendance_date' => $day->toDateString(),
                    'status' => Attendance::STATUS_PRESENT,
                ]);
                Attendance::create([
                    'employee_id' => $empCs->id,
                    'attendance_date' => $day->toDateString(),
                    'status' => Attendance::STATUS_PRESENT,
                ]);
            }
            $day->addDay();
        }
        // Karyawan tim lain (selain teknisi & CS) juga full — tandai semua hari kerja
        $others = $teamKpis->whereNotIn('employee_id', [$empTek->id, $empCs->id]);
        $day = $period->start_date->copy();
        while ($day->lte($attendanceEnd)) {
            if (! $day->isWeekend()) {
                foreach ($others as $tk) {
                    Attendance::create([
                        'employee_id' => $tk->employee_id,
                        'attendance_date' => $day->toDateString(),
                        'status' => Attendance::STATUS_PRESENT,
                    ]);
                }
            }
            $day->addDay();
        }
        // CS: setengah hari tercatat alpha; catatan yang hilang bukan alpha.
        $csHalf = (int) floor($workingDays / 2);
        Attendance::where('employee_id', $empCs->id)
            ->orderByDesc('attendance_date')
            ->skip($csHalf)
            ->take($workingDays - $csHalf)
            ->get()->each->update(['status' => Attendance::STATUS_ABSENT]);
        $expectedAttendance = round(
            ((($teamKpis->count() - 1) * 100.0) + round(($csHalf / $workingDays) * 100, 2)) / $teamKpis->count(),
            2
        );

        $res = app(TeamAggregationKpiSyncService::class)->syncPeriodTeamAggregation($period);
        $this->assertGreaterThanOrEqual(1, $res['updated_items']);

        $kpiSpv = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $empSpv->id)->first();
        $sup01 = $kpiSpv->items->firstWhere('definition_code_snapshot', 'SUP-01');
        $sup02 = $kpiSpv->items->firstWhere('definition_code_snapshot', 'SUP-02');
        $sup03 = $kpiSpv->items->firstWhere('definition_code_snapshot', 'SUP-03');

        // SUP-01: rata-rata final score seluruh tim
        $this->assertEquals($expectedAvgScore, (float) $sup01->actual_decimal);
        // SUP-02: rata-rata achievement seluruh item tim
        $this->assertEquals($expectedAvgAchievement, (float) $sup02->actual_decimal);
        // SUP-03: rata-rata kehadiran seluruh anggota tim (semua 100%, CS ~50%)
        $this->assertEquals($expectedAttendance, (float) $sup03->actual_decimal);
    }
}
