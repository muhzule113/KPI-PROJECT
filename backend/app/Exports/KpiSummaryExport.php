<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

final class KpiSummaryExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(
        private readonly Builder $reportQuery,
        private readonly string $periodName,
    ) {}

    public function query(): Builder
    {
        return $this->reportQuery;
    }

    public function headings(): array
    {
        return [
            'Periode',
            'Nomor Karyawan',
            'Nama Karyawan',
            'Jabatan',
            'Cabang',
            'Status KPI',
            'Progress (%)',
            'Skor Final',
            'Predikat',
            'Dikirim Pada',
            'Disetujui Pada',
            'Dikunci Pada',
        ];
    }

    public function map($row): array
    {
        return [
            $this->periodName,
            $row->employee_number,
            $row->employee_name,
            $row->position_name,
            $row->branch_name,
            $row->status,
            $row->progress_percentage !== null ? (float) $row->progress_percentage : null,
            $row->final_score !== null ? (float) $row->final_score : null,
            $row->rating_label,
            $row->submitted_at,
            $row->approved_at,
            $row->locked_at,
        ];
    }
}
