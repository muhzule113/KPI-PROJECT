<?php

namespace App\Filament\Widgets;

use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExecutiveStatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $activePeriod = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
        if (!$activePeriod) {
            $activePeriod = KpiPeriod::orderByDesc('id')->first();
        }

        if (!$activePeriod) {
            return [
                Stat::make('Periode Aktif', 'Belum ada periode')
                    ->description('Silakan buat periode baru di menu Periode Penilaian')
                    ->color('gray'),
            ];
        }

        $kpis = EmployeeKpi::where('period_id', $activePeriod->id)->get();
        $totalEligible = $kpis->count();
        $submittedCount = $kpis->whereIn('status', ['submitted', 'under_review', 'verified', 'pending_approval', 'approved', 'locked'])->count();
        $completionRate = $totalEligible > 0 ? round(($submittedCount / $totalEligible) * 100, 1) : 0;

        $approvedCount = $kpis->whereIn('status', ['approved', 'locked'])->count();
        $avgScore = $kpis->whereNotNull('final_score')->avg('final_score');
        $avgScoreFormatted = $avgScore !== null ? number_format((float)$avgScore, 2) : '0.00';

        $pendingReviewCount = $kpis->whereIn('status', ['submitted', 'under_review'])->count();
        $pendingApprovalCount = $kpis->where('status', 'pending_approval')->count();

        return [
            Stat::make('Kelengkapan Submission', "{$completionRate}%")
                ->description("{$submittedCount} dari {$totalEligible} karyawan telah submit")
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color($completionRate >= 80 ? 'success' : 'warning')
                ->chart([50, 65, 75, $completionRate]),

            Stat::make('Rata-rata Skor Periode Ini', $avgScoreFormatted)
                ->description($activePeriod->name)
                ->descriptionIcon('heroicon-m-sparkles')
                ->color('success')
                ->chart([80, 85, 90, (float)$avgScoreFormatted]),

            Stat::make('Menunggu Review Supervisor', (string) $pendingReviewCount)
                ->description('Submission dalam antrean review')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingReviewCount > 0 ? 'warning' : 'success'),

            Stat::make('Menunggu Approval Manager', (string) $pendingApprovalCount)
                ->description('Siap untuk persetujuan final')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color($pendingApprovalCount > 0 ? 'primary' : 'success'),
        ];
    }
}
