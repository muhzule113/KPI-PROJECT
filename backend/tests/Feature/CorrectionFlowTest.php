<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Models\User;
use App\Modules\Approval\ApprovalService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorrectionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function lockedKpi(): EmployeeKpi
    {
        $empTek = Employee::where('email', 'teknisi@toko.com')->first();
        $period = KpiPeriod::where('status', 'OPEN')->first();
        $kpi = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $empTek->id)->first();
        $kpi->load('items.assessment');
        foreach ($kpi->items as $item) {
            if ($item->formula_key_snapshot === 'rubric') {
                $item->actual_decimal = 100;
                $item->status = 'verified';
            } elseif ($item->actual_decimal === null) {
                $item->actual_decimal = $item->target_value_snapshot ?? 100;
            }
            $item->save();
        }
        $kpi->update(['status' => 'locked']);

        return $kpi->fresh(['items']);
    }

    public function test_requester_cannot_approve_own_correction(): void
    {
        $userMgr = User::where('email', 'manager@toko.com')->first();
        $kpi = $this->lockedKpi();
        $item = $kpi->items->first();

        $req = app(ApprovalService::class)->requestCorrection(
            kpi: $kpi,
            reason: 'Koreksi nilai aktual',
            afterData: ['items' => [['id' => $item->id, 'actual' => 99]]],
            requesterId: $userMgr->id,
        );

        $this->assertEquals('pending', $req->status);

        // Dual authorization: pihak yang sama tidak boleh menyetujui sendiri
        try {
            app(ApprovalService::class)->approveCorrection($req, $userMgr->id);
            $this->fail('Seharusnya melempar exception dual authorization');
        } catch (Exception $e) {
            $this->assertStringContainsString('Dual Authorization', $e->getMessage());
        }

        $this->assertEquals('pending', $req->fresh()->status);
    }

    public function test_approve_correction_applies_actual_and_recalculates(): void
    {
        $userMgr = User::where('email', 'manager@toko.com')->first();
        $userSpv = User::where('email', 'supervisor@toko.com')->first();
        $kpi = $this->lockedKpi();
        $item = $kpi->items->first();
        $oldActual = $item->actual_decimal;
        $newActual = (float) $oldActual + 7;

        $req = app(ApprovalService::class)->requestCorrection(
            kpi: $kpi,
            reason: 'Tiket telat tercatat',
            afterData: ['items' => [['id' => $item->id, 'actual' => $newActual]]],
            requesterId: $userMgr->id,
        );

        // Disetujui oleh pihak berbeda (supervisor)
        app(ApprovalService::class)->approveCorrection($req, $userSpv->id);

        $req->refresh();
        $this->assertEquals('applied', $req->status);
        $this->assertNotNull($req->applied_at);

        $item->refresh();
        $this->assertEquals($newActual, (float) $item->actual_decimal);
        $this->assertEquals(1, $req->employeeKpi->revision_number);
    }

    public function test_reject_correction(): void
    {
        $userMgr = User::where('email', 'manager@toko.com')->first();
        $userSpv = User::where('email', 'supervisor@toko.com')->first();
        $kpi = $this->lockedKpi();
        $item = $kpi->items->first();

        $req = app(ApprovalService::class)->requestCorrection(
            kpi: $kpi,
            reason: 'Koreksi nilai aktual',
            afterData: ['items' => [['id' => $item->id, 'actual' => 50]]],
            requesterId: $userMgr->id,
        );

        app(ApprovalService::class)->rejectCorrection($req, $userSpv->id, 'Data sudah benar');

        $this->assertEquals('rejected', $req->fresh()->status);
        // Nilai aktual tidak berubah
        $this->assertEquals((float) $item->actual_decimal, (float) $item->fresh()->actual_decimal);
    }
}
