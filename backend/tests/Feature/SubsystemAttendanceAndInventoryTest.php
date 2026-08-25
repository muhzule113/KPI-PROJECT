<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Modules\Assessment\AttendanceKpiSyncService;
use App\Modules\Assessment\InventoryKpiSyncService;
use App\Modules\Assessment\StockOpnameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubsystemAttendanceAndInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_attendance_sync_updates_discipline_kpi(): void
    {
        $empGud = Employee::where('email', 'gudang@toko.com')->first();
        $period = KpiPeriod::where('status', 'OPEN')->first();

        $this->assertNotNull($empGud);
        $this->assertNotNull($period);

        // Catat hadir untuk SEMUA hari kerja dalam periode
        $workingDays = 0;
        $date = $period->start_date->copy();
        while ($date->lte($period->end_date)) {
            if (!$date->isWeekend()) {
                Attendance::create([
                    'employee_id' => $empGud->id,
                    'branch_id' => $empGud->branch_id,
                    'attendance_date' => $date->toDateString(),
                    'status' => Attendance::STATUS_PRESENT,
                    'check_in_time' => '08:00:00',
                ]);
                $workingDays++;
            }
            $date->addDay();
        }
        $this->assertGreaterThan(0, $workingDays);

        $res = app(AttendanceKpiSyncService::class)->syncPeriodAttendanceData($period);

        $kpi = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $empGud->id)->first();
        $gud07 = $kpi?->items->firstWhere('definition_code_snapshot', 'GUD-07');

        $this->assertNotNull($gud07, 'GUD-07 harus ada di KPI gudang');
        $this->assertEquals(100.0, (float) $gud07->actual_decimal);
        $this->assertGreaterThanOrEqual(1, $res['updated_items']);
    }

    public function test_attendance_sync_counts_absent_days(): void
    {
        $empGud = Employee::where('email', 'gudang@toko.com')->first();
        $period = KpiPeriod::where('status', 'OPEN')->first();

        // Hanya 1 hari hadir — sisanya alpha (tidak dicatat)
        $firstWorkingDay = $period->start_date->copy();
        while ($firstWorkingDay->isWeekend()) {
            $firstWorkingDay->addDay();
        }
        Attendance::create([
            'employee_id' => $empGud->id,
            'branch_id' => $empGud->branch_id,
            'attendance_date' => $firstWorkingDay->toDateString(),
            'status' => Attendance::STATUS_PRESENT,
        ]);

        app(AttendanceKpiSyncService::class)->syncPeriodAttendanceData($period);

        $kpi = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $empGud->id)->first();
        $gud07 = $kpi->items->firstWhere('definition_code_snapshot', 'GUD-07');

        $this->assertLessThan(100.0, (float) $gud07->actual_decimal);
        $this->assertGreaterThan(0.0, (float) $gud07->actual_decimal);
    }

    public function test_opname_complete_adjusts_stock_and_syncs_gudang_kpi(): void
    {
        $empGud = Employee::where('email', 'gudang@toko.com')->first();
        $userGud = User::where('email', 'gudang@toko.com')->first();
        $period = KpiPeriod::where('status', 'OPEN')->first();

        $parts = Sparepart::orderBy('id')->take(3)->get();
        $this->assertCount(3, $parts);

        $parts[0]->update(['stock_quantity' => 10]);
        $parts[1]->update(['stock_quantity' => 5]);
        $parts[2]->update(['stock_quantity' => 8]);

        // Buat sesi opname + snapshot
        $opname = StockOpname::create([
            'code' => 'OPN-TEST-001',
            'period_id' => $period->id,
            'status' => StockOpname::STATUS_IN_PROGRESS,
            'created_by' => $userGud->id,
        ]);
        app(StockOpnameService::class)->snapshotItems($opname);

        // Isi stok fisik: 2 item akurat, 1 item selisih (sistem 5 → fisik 3)
        $items = $opname->items->whereIn('sparepart_id', $parts->pluck('id'))->values();
        $this->assertCount(3, $items);
        $items[0]->update(['physical_stock' => 10]);
        $items[1]->update(['physical_stock' => 3]);
        $items[2]->update(['physical_stock' => 8]);

        // Selesaikan opname via service (logika yang dipakai Filament action)
        $res = app(StockOpnameService::class)->complete($opname, $userGud->id);
        $this->assertEquals(3, $res['counted']);
        $this->assertEquals(1, $res['adjusted']);

        // Stok sparepart part[1] disesuaikan 5 → 3
        $this->assertEquals(3, $parts[1]->fresh()->stock_quantity);
        // Ledger mutasi tercatat
        $movement = StockMovement::where('reference_type', 'stock_opname')->where('reference_id', $opname->id)->first();
        $this->assertNotNull($movement);
        $this->assertEquals(-2, $movement->quantity);

        // Sync KPI gudang
        app(InventoryKpiSyncService::class)->syncPeriodInventoryData($period);

        $kpi = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $empGud->id)->first();
        $gud01 = $kpi->items->firstWhere('definition_code_snapshot', 'GUD-01');
        $gud02 = $kpi->items->firstWhere('definition_code_snapshot', 'GUD-02');
        $gud05 = $kpi->items->firstWhere('definition_code_snapshot', 'GUD-05');

        // GUD-01: 2/3 akurat = 66.67%
        $this->assertEquals(round(2 / 3 * 100, 2), (float) $gud01->actual_decimal);
        // GUD-02: selisih 2 dari total sistem 23 = 8.70%
        $this->assertEquals(round(2 / 23 * 100, 2), (float) $gud02->actual_decimal);
        // GUD-05: 1 dari 1 sesi selesai = 100%
        $this->assertEquals(100.0, (float) $gud05->actual_decimal);
    }
}
