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

    public function test_attendance_sync_excludes_permission_and_sick_leave_from_ratio(): void
    {
        $empGud = Employee::where('email', 'gudang@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $excusedStatuses = [Attendance::STATUS_PERMISSION, Attendance::STATUS_SICK_LEAVE];
        $excusedIndex = 0;

        $date = $period->start_date->copy();
        while ($date->lte($period->end_date)) {
            if (!$date->isWeekend()) {
                $status = $excusedIndex < count($excusedStatuses)
                    ? $excusedStatuses[$excusedIndex++]
                    : Attendance::STATUS_PRESENT;

                Attendance::create([
                    'employee_id' => $empGud->id,
                    'branch_id' => $empGud->branch_id,
                    'attendance_date' => $date->toDateString(),
                    'status' => $status,
                    'check_in_time' => $status === Attendance::STATUS_PRESENT ? '08:00:00' : null,
                    'note' => $status === Attendance::STATUS_PRESENT ? null : 'Disetujui atasan',
                ]);
            }
            $date->addDay();
        }

        $this->assertSame(100.0, app(AttendanceKpiSyncService::class)->calculateAttendanceRate($empGud, $period));
    }

    public function test_attendance_sync_removes_stale_daily_value_when_day_becomes_excused(): void
    {
        $empGud = Employee::where('email', 'gudang@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $date = $period->start_date->copy();
        while ($date->isWeekend()) {
            $date->addDay();
        }

        $attendance = Attendance::create([
            'employee_id' => $empGud->id,
            'branch_id' => $empGud->branch_id,
            'attendance_date' => $date->toDateString(),
            'status' => Attendance::STATUS_PRESENT,
            'check_in_time' => '08:00:00',
        ]);
        $sync = app(AttendanceKpiSyncService::class);
        $sync->syncPeriodAttendanceData($period);

        $item = EmployeeKpi::where('period_id', $period->id)
            ->where('employee_id', $empGud->id)
            ->firstOrFail()
            ->items
            ->firstWhere('definition_code_snapshot', 'GUD-07');
        $entry = $item->dailyEntries()->whereDate('entry_date', $date)->firstOrFail();
        $this->assertSame(100.0, (float) $entry->system_actual_decimal);

        $attendance->update([
            'status' => Attendance::STATUS_PERMISSION,
            'check_in_time' => null,
            'note' => 'Izin resmi',
        ]);
        $sync->syncPeriodAttendanceData($period);

        $entry->refresh();
        $this->assertNull($entry->system_actual_decimal);
        $this->assertSame('draft', $entry->entry_status);
        $this->assertSame('pending', $entry->supervisor_status);
        $this->assertSame('pending', $entry->manager_status);
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

        // Isi stok fisik: PRT-BAT (sistem 5) salah hitung → fisik 3, dua sisanya akurat
        $items = $opname->items;
        $selectedItems = $items->whereIn('sparepart_id', $parts->pluck('id'))->values();
        $this->assertCount(3, $selectedItems);
        foreach ($items as $it) {
            $it->update(['physical_stock' => $it->system_stock]); // akurat dulu
        }
        $batItem = $selectedItems->firstWhere('sparepart_id', $parts[1]->id);
        $batItem->update(['physical_stock' => 3]); // selisih: sistem 5 → fisik 3

        // Selesaikan opname via service (logika yang dipakai action web)
        $res = app(StockOpnameService::class)->complete($opname, $userGud->id);
        $this->assertEquals($items->count(), $res['counted']);
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

        // GUD-01/GUD-02 use the complete branch snapshot, not only the three selected fixtures.
        $counted = $opname->fresh('items')->items;
        $accurate = $counted->where('difference', 0)->count();
        $totalSystem = $counted->sum('system_stock');
        $totalDifference = $counted->sum(fn ($item) => abs($item->difference));
        $this->assertEquals(round(($accurate / $counted->count()) * 100, 2), (float) $gud01->actual_decimal);
        $this->assertEquals(round(($totalDifference / $totalSystem) * 100, 2), (float) $gud02->actual_decimal);
        // GUD-02 is the absolute difference divided by system stock.
        // GUD-05: 1 dari 1 sesi selesai = 100%
        $this->assertEquals(100.0, (float) $gud05->actual_decimal);
    }
}
