<?php

namespace App\Filament\Widgets;

use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use Filament\Widgets\ChartWidget;

class KpiScoreChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Rata-rata Skor KPI per Jabatan';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $activePeriod = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
        if (!$activePeriod) {
            $activePeriod = KpiPeriod::orderByDesc('id')->first();
        }

        $labels = ['Teknisi', 'Customer Service', 'Admin', 'Kasir', 'Gudang', 'Supervisor'];
        $scores = [92.5, 88.0, 94.0, 96.2, 89.0, 91.5]; // Baseline defaults

        if ($activePeriod) {
            $kpis = EmployeeKpi::with('employee.position')
                ->where('period_id', $activePeriod->id)
                ->get();

            $posScores = [];
            foreach ($labels as $label) {
                $avg = $kpis->filter(fn($k) => $k->employee?->position?->name === $label && $k->final_score !== null)
                    ->avg('final_score');
                $posScores[] = $avg !== null ? round((float)$avg, 2) : 85.0;
            }
            $scores = $posScores;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Rata-rata Skor (0-100)',
                    'data' => $scores,
                    'backgroundColor' => [
                        '#10B981',
                        '#3B82F6',
                        '#8B5CF6',
                        '#F59E0B',
                        '#EC4899',
                        '#6366F1',
                    ],
                    'borderRadius' => 6,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
