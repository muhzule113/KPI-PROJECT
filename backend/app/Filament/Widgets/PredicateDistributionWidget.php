<?php

namespace App\Filament\Widgets;

use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use Filament\Widgets\ChartWidget;

class PredicateDistributionWidget extends ChartWidget
{
    protected static ?string $heading = 'Distribusi Predikat Kinerja';

    protected static ?int $sort = 3;

    protected function getData(): array
    {
        $activePeriod = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
        if (!$activePeriod) {
            $activePeriod = KpiPeriod::orderByDesc('id')->first();
        }

        $labels = ['⭐ Istimewa (95-100)', 'Sangat Baik (90-94.9)', 'Baik (80-89.9)', 'Cukup (70-79.9)', 'Perlu Perbaikan (<70)'];
        $counts = [2, 3, 1, 0, 0];

        if ($activePeriod) {
            $kpis = EmployeeKpi::where('period_id', $activePeriod->id)->whereNotNull('final_score')->get();
            if ($kpis->isNotEmpty()) {
                $counts = [
                    $kpis->where('final_score', '>=', 95.0)->count(),
                    $kpis->where('final_score', '>=', 90.0)->where('final_score', '<', 95.0)->count(),
                    $kpis->where('final_score', '>=', 80.0)->where('final_score', '<', 90.0)->count(),
                    $kpis->where('final_score', '>=', 70.0)->where('final_score', '<', 80.0)->count(),
                    $kpis->where('final_score', '<', 70.0)->count(),
                ];
            }
        }

        return [
            'datasets' => [
                [
                    'label' => 'Jumlah Karyawan',
                    'data' => $counts,
                    'backgroundColor' => [
                        '#10B981', // Istimewa
                        '#3B82F6', // Sangat Baik
                        '#84CC16', // Baik
                        '#F59E0B', // Cukup
                        '#EF4444', // Perlu Perbaikan
                    ],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
