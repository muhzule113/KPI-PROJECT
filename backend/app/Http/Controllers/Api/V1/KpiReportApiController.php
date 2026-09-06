<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ArrayReportExport;
use App\Exports\KpiSummaryExport;
use App\Http\Controllers\Controller;
use App\Modules\Reporting\KpiReportService;
use App\Support\CapabilityMatrix;
use App\Support\SpreadsheetValue;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class KpiReportApiController extends Controller
{
    public function __construct(
        protected KpiReportService $reportService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! CapabilityMatrix::has($request->user(), 'reports.view')) {
            return response()->json(['success' => false, 'message' => 'Anda tidak berwenang melihat laporan KPI.'], 403);
        }

        $filters = $request->validate([
            'period_id' => ['nullable', 'integer', 'exists:kpi_periods,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
        ]);
        $result = $this->reportService->reportData($request->user(), $filters['period_id'] ?? null, $filters['position_id'] ?? null);

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'id' => $result['period']->id,
                    'name' => $result['period']->name,
                    'status' => $result['period']->status,
                    'start_date' => $result['period']->start_date?->toDateString(),
                    'end_date' => $result['period']->end_date?->toDateString(),
                ],
                'rows' => $result['query']->get()
                    ->map(fn (object $row): array => $this->reportService->rowPayload($row, $result['period']))
                    ->values(),
            ],
        ]);
    }

    public function export(Request $request, string $format): Response|StreamedResponse|BinaryFileResponse
    {
        if (! CapabilityMatrix::has($request->user(), 'reports.export')) {
            abort(403, 'Anda tidak berwenang mengunduh laporan KPI.');
        }

        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 404);
        $filters = $request->validate([
            'period_id' => ['nullable', 'integer', 'exists:kpi_periods,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
        ]);
        $result = $this->reportService->reportData($request->user(), $filters['period_id'] ?? null, $filters['position_id'] ?? null);
        $period = $result['period'];
        $query = $result['query'];
        $filename = sprintf('rekap-kpi-%04d-%02d', $period->year, $period->month);

        return match ($format) {
            'xlsx' => Excel::download(
                new KpiSummaryExport($query, $period->name),
                $filename.'.xlsx',
                \Maatwebsite\Excel\Excel::XLSX,
                ['Cache-Control' => 'no-store, private'],
            ),
            'pdf' => Pdf::loadView('reports.kpi-summary', [
                'period' => $period,
                'rows' => $query->get(),
            ])->setPaper('a4', 'landscape')->download($filename.'.pdf'),
            default => $this->csv($query, $period, $filename.'.csv'),
        };
    }

    public function typedIndex(Request $request, string $type): JsonResponse
    {
        $filters = $request->validate([
            'period_id' => ['nullable', 'integer', 'exists:kpi_periods,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'kpi_id' => ['nullable', 'string', 'exists:employee_kpis,id'],
        ]);
        $report = $this->reportService->typedReport($request->user(), $type, $filters);

        return response()->json(['success' => true, 'data' => ['type' => $type, 'period' => $report['period']->only(['id', 'name', 'status']), 'rows' => $report['rows'], 'formats' => $report['formats']]]);
    }

    public function typedExport(Request $request, string $type, string $format): Response|BinaryFileResponse
    {
        abort_unless(CapabilityMatrix::has($request->user(), 'reports.export'), 403);
        $filters = $request->validate([
            'period_id' => ['nullable', 'integer', 'exists:kpi_periods,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'kpi_id' => ['nullable', 'string', 'exists:employee_kpis,id'],
        ]);
        $report = $this->reportService->typedReport($request->user(), $type, $filters);
        abort_unless(in_array($format, $report['formats'], true), 404);
        $filename = $type.'-'.$report['period']->year.'-'.$report['period']->month.'.'.$format;

        return $format === 'xlsx'
            ? Excel::download(new ArrayReportExport($report['headings'], $report['rows']), $filename, \Maatwebsite\Excel\Excel::XLSX, ['Cache-Control' => 'no-store, private'])
            : Pdf::loadView('reports.generic', ['title' => $type.' - '.$report['period']->name, 'headings' => $report['headings'], 'rows' => $report['rows']])
                ->setPaper('a4', 'landscape')->download($filename);
    }

    private function csv($query, $period, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query, $period): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Periode', 'Nomor Karyawan', 'Nama Karyawan', 'Jabatan', 'Cabang',
                'Status KPI', 'Progress (%)', 'Skor Final', 'Predikat', 'Dikirim Pada',
                'Disetujui Pada', 'Dikunci Pada',
            ], ';');

            foreach ($query->cursor() as $row) {
                fputcsv($handle, SpreadsheetValue::row([
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
                ]), ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
