<?php

namespace App\Http\Controllers\Web;

use App\Exports\KpiSummaryExport;
use App\Http\Controllers\Controller;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Support\MenuAccess;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class KpiReportController extends Controller
{
    public function export(Request $request): StreamedResponse
    {
        [$period, $query] = $this->reportData($request);

        $filename = sprintf('rekap-kpi-%04d-%02d.csv', $period->year, $period->month);

        return response()->streamDownload(function () use ($query, $period): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
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
            ], ';');

            foreach ($query->cursor() as $row) {
                fputcsv($handle, [
                    $period->name,
                    $row->employee_number,
                    $row->employee_name,
                    $row->position_name,
                    $row->branch_name,
                    $row->status,
                    $row->progress_percentage !== null ? number_format((float) $row->progress_percentage, 2, '.', '') : '',
                    $row->final_score !== null ? number_format((float) $row->final_score, 2, '.', '') : '',
                    $row->rating_label,
                    $row->submitted_at,
                    $row->approved_at,
                    $row->locked_at,
                ], ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function exportXlsx(Request $request): BinaryFileResponse
    {
        [$period, $query] = $this->reportData($request);

        return Excel::download(
            new KpiSummaryExport($query, $period->name),
            sprintf('rekap-kpi-%04d-%02d.xlsx', $period->year, $period->month),
            \Maatwebsite\Excel\Excel::XLSX,
            ['Cache-Control' => 'no-store, private'],
        );
    }

    public function exportPdf(Request $request): Response
    {
        [$period, $query] = $this->reportData($request);

        return Pdf::loadView('reports.kpi-summary', [
            'period' => $period,
            'rows' => $query->get(),
        ])
            ->setPaper('a4', 'landscape')
            ->download(sprintf('rekap-kpi-%04d-%02d.pdf', $period->year, $period->month));
    }

    /**
     * @return array{0: KpiPeriod, 1: Builder}
     */
    private function reportData(Request $request): array
    {
        abort_unless(MenuAccess::can($request->user(), ['owner_manager', 'super_admin', 'auditor'], []), 403);

        $filters = $request->validate([
            'period_id' => ['nullable', 'integer', 'exists:kpi_periods,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
        ]);

        $period = isset($filters['period_id'])
            ? KpiPeriod::query()->findOrFail($filters['period_id'])
            : KpiPeriod::query()
                ->where('status', 'OPEN')
                ->orderByDesc('id')
                ->first()
                ?? KpiPeriod::query()
                    ->whereNotIn('status', ['DRAFT', 'CANCELLED'])
                    ->orderByDesc('id')
                    ->firstOrFail();

        $query = EmployeeKpi::query()
            ->join('employees', 'employees.id', '=', 'employee_kpis.employee_id')
            ->leftJoin('positions', 'positions.id', '=', 'employees.position_id')
            ->leftJoin('branches', 'branches.id', '=', 'employees.branch_id')
            ->where('employee_kpis.period_id', $period->getKey())
            ->when(
                $filters['position_id'] ?? null,
                fn (Builder $query, int $positionId) => $query->where('employees.position_id', $positionId),
            )
            ->select([
                'employee_kpis.id as kpi_id',
                'employee_kpis.status',
                'employee_kpis.progress_percentage',
                'employee_kpis.final_score',
                'employee_kpis.rating_label',
                'employee_kpis.submitted_at',
                'employee_kpis.approved_at',
                'employee_kpis.locked_at',
                'employees.employee_number',
                'employees.name as employee_name',
                'positions.name as position_name',
                'branches.name as branch_name',
            ])
            ->orderBy('employees.name')
            ->orderBy('employee_kpis.id');

        return [$period, $query];
    }
}
