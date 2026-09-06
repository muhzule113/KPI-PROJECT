<?php

namespace Database\Seeders;

use App\Models\KpiPeriod;
use App\Models\Sparepart;
use App\Models\User;
use App\Modules\Assessment\DailyAssessmentService;
use App\Modules\Period\PeriodService;

/** Dataset aplikasi demo: seluruh role, periode berjalan, dan satu siklus terselesaikan. */
class DemoSeeder extends DatabaseSeeder
{
    public function run(): void
    {
        parent::run();
        $this->call(WorkflowDemoSeeder::class);
    }

    protected function seedActivePeriod($branchPusat, $branchSurabaya): void
    {
        $period = KpiPeriod::firstOrCreate(['year' => now()->year, 'month' => now()->month], [
            'name' => 'Periode '.now()->translatedFormat('F Y'),
            'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(),
            'submission_deadline' => now()->endOfMonth()->addDay(),
            'review_deadline' => now()->endOfMonth()->addDays(3),
            'approval_deadline' => now()->endOfMonth()->addDays(5),
            'status' => 'DRAFT', 'created_by' => User::where('email', 'kpi_admin@kpi.com')->value('id'),
        ]);
        $period->branches()->syncWithoutDetaching([$branchPusat->id, $branchSurabaya->id]);
        if (in_array($period->status, ['DRAFT', 'READY'], true)) {
            app(PeriodService::class)->openPeriod($period);
        }
        foreach ([[$branchPusat, 'PRT-LCD-SMA54'], [$branchSurabaya, 'PRT-LCD-SMA54-SBY']] as [$branch, $code]) {
            Sparepart::firstOrCreate(['code' => $code], ['name' => 'Layar Samsung A54', 'category' => 'LCD', 'product_type' => 'sparepart', 'branch_id' => $branch->id, 'stock_quantity' => 10, 'min_stock_alert' => 2, 'purchase_price' => 550000, 'selling_price' => 850000, 'is_critical' => true]);
        }
        app(DailyAssessmentService::class)->preparePeriod($period->fresh());
    }
}
