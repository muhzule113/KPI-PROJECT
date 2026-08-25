<?php

namespace Tests\Feature;

use App\Models\CoachingLog;
use App\Models\Complaint;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Modules\Assessment\CoachingKpiSyncService;
use App\Modules\Assessment\ComplaintKpiSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplaintAndCoachingKpiTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_complaint_sync_sets_cs_count_and_supervisor_sla_rate(): void
    {
        $empCs = Employee::where('email', 'cs@toko.com')->first();
        $empTek = Employee::where('email', 'teknisi@toko.com')->first();
        $empSpv = Employee::where('email', 'supervisor@toko.com')->first();
        $period = KpiPeriod::where('status', 'OPEN')->first();

        $this->assertNotNull($empCs);
        $this->assertNotNull($empSpv);

        // 3 komplain terhadap CS (2 selesai, 1 tepat waktu) + 1 komplain terhadap Teknisi (selesai tepat waktu)
        $sla1 = $period->start_date->copy()->addDays(4);
        Complaint::create([
            'code' => 'CMP-TEST-001',
            'complaint_date' => $period->start_date->copy()->addDay(),
            'employee_id' => $empCs->id,
            'channel' => 'whatsapp',
            'status' => Complaint::STATUS_RESOLVED,
            'description' => 'Komplain A',
            'sla_deadline' => $sla1,
            'resolved_at' => $sla1->copy()->subDay(),
        ]);
        Complaint::create([
            'code' => 'CMP-TEST-002',
            'complaint_date' => $period->start_date->copy()->addDay(),
            'employee_id' => $empCs->id,
            'channel' => 'in_store',
            'status' => Complaint::STATUS_RESOLVED,
            'description' => 'Komplain B (telat)',
            'sla_deadline' => $sla1->copy()->addDay(),
            'resolved_at' => $sla1->copy()->addDays(3),
        ]);
        Complaint::create([
            'code' => 'CMP-TEST-003',
            'complaint_date' => $period->start_date->copy()->addDays(2),
            'employee_id' => $empCs->id,
            'channel' => 'phone',
            'status' => Complaint::STATUS_OPEN,
            'description' => 'Komplain C (masih terbuka)',
            'sla_deadline' => $sla1->copy()->addDays(2),
        ]);
        Complaint::create([
            'code' => 'CMP-TEST-004',
            'complaint_date' => $period->start_date->copy()->addDays(3),
            'employee_id' => $empTek->id,
            'channel' => 'google_review',
            'status' => Complaint::STATUS_RESOLVED,
            'description' => 'Komplain servis teknisi',
            'sla_deadline' => $sla1->copy()->addDays(3),
            'resolved_at' => $sla1->copy()->addDays(3),
        ]);

        $res = app(ComplaintKpiSyncService::class)->syncPeriodComplaintData($period);
        $this->assertGreaterThanOrEqual(2, $res['updated_items']);

        // CS-05: 3 komplain terhadap CS
        $kpiCs = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $empCs->id)->first();
        $cs05 = $kpiCs->items->firstWhere('definition_code_snapshot', 'CS-05');
        $this->assertNotNull($cs05);
        $this->assertEquals(3.0, (float) $cs05->actual_decimal);

        // SUP-04: 4 komplain tim (CS+teknisi), 2 selesai tepat waktu = 50%
        $kpiSpv = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $empSpv->id)->first();
        $sup04 = $kpiSpv->items->firstWhere('definition_code_snapshot', 'SUP-04');
        $this->assertNotNull($sup04);
        $this->assertEquals(50.0, (float) $sup04->actual_decimal);
    }

    public function test_coaching_sync_sets_supervisor_coverage_kpi(): void
    {
        $empSpv = Employee::where('email', 'supervisor@toko.com')->first();
        $teamMembers = Employee::where('supervisor_id', $empSpv->id)->where('status', 'active')->get();
        $period = KpiPeriod::where('status', 'OPEN')->first();

        $this->assertGreaterThanOrEqual(2, $teamMembers->count());

        // Coach 2 anggota tim yang berbeda
        CoachingLog::create([
            'supervisor_id' => $empSpv->id,
            'employee_id' => $teamMembers[0]->id,
            'coaching_date' => $period->start_date->copy()->addDay(),
            'topic' => 'Teknik diagnosa',
            'target_met' => true,
        ]);
        CoachingLog::create([
            'supervisor_id' => $empSpv->id,
            'employee_id' => $teamMembers[1]->id,
            'coaching_date' => $period->start_date->copy()->addDays(2),
            'topic' => 'Komunikasi pelanggan',
            'target_met' => false,
        ]);

        $res = app(CoachingKpiSyncService::class)->syncPeriodCoachingData($period);
        $this->assertEquals(1, $res['updated_items']);

        $kpiSpv = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $empSpv->id)->first();
        $sup05 = $kpiSpv->items->firstWhere('definition_code_snapshot', 'SUP-05');

        $this->assertNotNull($sup05);
        // coverage = 2 / ukuran tim × 100
        $expected = round((2 / $teamMembers->count()) * 100, 2);
        $this->assertEquals($expected, (float) $sup05->actual_decimal);
    }
}
