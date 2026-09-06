<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\Sparepart;
use App\Models\SparepartRequest;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\User;
use App\Modules\Assessment\AttendanceKpiSyncService;
use App\Modules\Assessment\InventoryKpiSyncService;
use App\Modules\Assessment\OperationalKpiSyncService;
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
            if (! $date->isWeekend()) {
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

    public function test_attendance_sync_does_not_treat_missing_records_as_absence(): void
    {
        $empGud = Employee::where('email', 'gudang@toko.com')->first();
        $period = KpiPeriod::where('status', 'OPEN')->first();

        // Satu hari hadir; tanggal tanpa catatan masih menunggu input absensi.
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

        $this->assertSame(100.0, (float) $gud07->actual_decimal);
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
            if (! $date->isWeekend()) {
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

    public function test_inventory_and_fulfillment_facts_are_scoped_to_branch_and_assigned_warehouse(): void
    {
        $warehouse = User::where('email', 'gudang@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $branch = Branch::create(['code' => 'TEST-OPNAME-B', 'name' => 'Cabang Opname B']);
        $period->branches()->attach($branch->id);
        $otherUser = User::factory()->create();
        $otherUser->assignRole('employee');
        $otherEmployee = $warehouse->employee->replicate();
        $otherEmployee->fill(['user_id' => $otherUser->id, 'branch_id' => $branch->id,
            'email' => $otherUser->email, 'employee_number' => 'TEST-GUD-B']);
        $otherEmployee->save();
        $mainKpi = EmployeeKpi::where('period_id', $period->id)->where('employee_id', $warehouse->employee->id)->firstOrFail();
        $otherKpi = $mainKpi->replicate();
        $otherKpi->employee_id = $otherEmployee->id;
        $otherKpi->branch_id_snapshot = $branch->id;
        $otherKpi->save();
        foreach ($mainKpi->items as $item) {
            $copy = $item->replicate();
            $copy->employee_kpi_id = $otherKpi->id;
            $copy->save();
        }
        Sparepart::query()->update(['is_critical' => false]);
        $mainPart = Sparepart::firstOrFail()->replicate();
        $mainPart->fill(['code' => 'TEST-CRITICAL-A', 'branch_id' => $warehouse->employee->branch_id, 'stock_quantity' => 5, 'is_critical' => true]);
        $mainPart->save();
        $otherPart = $mainPart->replicate();
        $otherPart->fill(['code' => 'TEST-CRITICAL-B', 'branch_id' => $branch->id, 'stock_quantity' => 10]);
        $otherPart->save();
        $service = app(StockOpnameService::class);
        foreach ([[$warehouse, $mainPart, 'TEST-OPN-A'], [$otherUser, $otherPart, 'TEST-OPN-B']] as [$actor, $part, $code]) {
            $opname = StockOpname::create(['code' => $code, 'period_id' => $period->id, 'created_by' => $actor->id, 'branch_id' => $branch->id]);
            $this->assertSame((string) $actor->employee->branch_id, (string) $opname->branch_id);
            $service->snapshotItems($opname);
            $this->assertTrue($opname->items->every(fn ($item) => (string) $item->sparepart->branch_id === (string) $actor->employee->branch_id));
            foreach ($opname->items as $item) {
                $item->update(['physical_stock' => $actor->id === $warehouse->id ? $item->system_stock : 0]);
            }
            $service->complete($opname, $actor->id);
        }
        $date = $period->start_date->copy()->addHours(8);
        $templateTicket = ServiceTicket::firstOrFail();
        foreach ([[$warehouse->employee, $mainPart, 5], [$otherEmployee, $otherPart, 90], [$warehouse->employee, $otherPart, 90]] as $index => [$employee, $part, $minutes]) {
            $ticket = $templateTicket->replicate();
            $ticket->fill(['ticket_number' => 'TEST-GUD-SLA-'.$index, 'period_id' => $period->id, 'branch_id' => $part->branch_id]);
            $ticket->save();
            $request = SparepartRequest::create(['service_ticket_id' => $ticket->id, 'sparepart_id' => $part->id,
                'technician_employee_id' => $ticket->technician_employee_id, 'warehouse_employee_id' => $employee->id,
                'quantity' => 1, 'status' => 'fulfilled', 'requested_at' => $date, 'fulfilled_at' => $date->copy()->addMinutes($minutes)]);
            $request->forceFill(['created_at' => $date])->save();
        }
        app(OperationalKpiSyncService::class)->syncPeriodOperationalData($period);
        $mainKpi->refresh()->load('items');
        $otherKpi->refresh()->load('items');
        $this->assertSame(100.0, (float) $mainKpi->items->firstWhere('definition_code_snapshot', 'GUD-01')->actual_decimal);
        $this->assertSame(0.0, (float) $otherKpi->items->firstWhere('definition_code_snapshot', 'GUD-01')->actual_decimal);
        $this->assertSame(100.0, (float) $mainKpi->items->firstWhere('definition_code_snapshot', 'GUD-03')->actual_decimal);
        $this->assertSame(0.0, (float) $otherKpi->items->firstWhere('definition_code_snapshot', 'GUD-03')->actual_decimal);
        $this->assertSame(100.0, (float) $mainKpi->items->firstWhere('definition_code_snapshot', 'GUD-04')->actual_decimal);
        $this->assertSame(0.0, (float) $otherKpi->items->firstWhere('definition_code_snapshot', 'GUD-04')->actual_decimal);
    }
}
