<?php

namespace App\Modules\Reporting;

use App\Models\AuditEvent;
use App\Models\CoachingLog;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\ImportBatch;
use App\Models\KpiPeriod;
use App\Models\User;
use App\Support\CapabilityMatrix;
use App\Support\KpiVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class KpiReportService
{
    public const FORMATS = [
        'period_summary' => ['xlsx', 'pdf'],
        'position_summary' => ['xlsx', 'pdf'],
        'individual' => ['pdf'],
        'target_actual' => ['xlsx'],
        'ranking' => ['pdf'],
        'below_target' => ['xlsx'],
        'workflow_completeness' => ['xlsx'],
        'change_history' => ['xlsx'],
        'import_reconciliation' => ['xlsx'],
        'coaching_action_plan' => ['pdf'],
    ];

    /**
     * @return array{period: KpiPeriod, query: Builder}
     */
    public function reportData(User $user, ?int $periodId = null, ?int $positionId = null): array
    {
        abort_unless(CapabilityMatrix::has($user, 'reports.view'), 403);
        $period = $periodId
            ? KpiPeriod::query()->findOrFail($periodId)
            : KpiPeriod::query()
                ->where('status', 'OPEN')
                ->orderByDesc('id')
                ->first()
                ?? KpiPeriod::query()
                    ->whereNotIn('status', ['DRAFT', 'CANCELLED'])
                    ->orderByDesc('id')
                    ->firstOrFail();

        $query = KpiVisibility::applyScope(EmployeeKpi::query(), $user)
            ->join('employees', 'employees.id', '=', 'employee_kpis.employee_id')
            ->leftJoin('positions', 'positions.id', '=', 'employee_kpis.position_id_snapshot')
            ->leftJoin('branches', 'branches.id', '=', 'employee_kpis.branch_id_snapshot')
            ->where('employee_kpis.period_id', $period->getKey())
            ->when(
                $positionId,
                fn (Builder $builder, int $id) => $builder->where('employee_kpis.position_id_snapshot', $id),
            )
            ->select([
                'employee_kpis.id as kpi_id',
                'employee_kpis.status',
                'employee_kpis.progress_percentage',
                'employee_kpis.submitted_at',
                'employee_kpis.approved_at',
                'employee_kpis.locked_at',
                DB::raw('COALESCE(employee_kpis.employee_number_snapshot, employees.employee_number) as employee_number'),
                DB::raw('COALESCE(employee_kpis.employee_name_snapshot, employees.name) as employee_name'),
                'positions.name as position_name',
                'branches.name as branch_name',
            ])
            ->orderBy('employees.name')
            ->orderBy('employee_kpis.id');

        foreach (['final_score', 'rating_label'] as $field) {
            // Hasil tim tersedia bagi penilai; KPI dirinya sendiri tetap menunggu publikasi.
            if (! KpiVisibility::published($period) && $user->employee) {
                $query->selectRaw("CASE WHEN employee_kpis.employee_id = ? THEN NULL ELSE employee_kpis.$field END AS $field", [$user->employee->id]);
            } else {
                $query->addSelect('employee_kpis.'.$field);
            }
        }

        return ['period' => $period, 'query' => $query];
    }

    public function typedReport(User $user, string $type, array $filters = []): array
    {
        abort_unless(isset(self::FORMATS[$type]), 404);
        $result = $this->reportData($user, $filters['period_id'] ?? null, $filters['position_id'] ?? null);
        $period = $result['period'];
        $query = $result['query'];
        $kpiIds = (clone $query)->reorder()->select('employee_kpis.id')->pluck('employee_kpis.id');
        $baseRows = fn () => (clone $query)->get()->map(fn (object $row) => $this->rowPayload($row, $period))->values()->all();

        [$headings, $rows] = match ($type) {
            'period_summary' => [[
                'KPI ID', 'Periode', 'Nomor Karyawan', 'Nama Karyawan', 'Jabatan', 'Cabang', 'Status', 'Progress', 'Skor Final', 'Predikat', 'Dikirim', 'Disetujui', 'Dikunci',
            ], $baseRows()],
            'position_summary' => [['Jabatan', 'Jumlah KPI', 'Rata-rata Skor'], collect($baseRows())->groupBy('position_name')->map(fn ($items, $position) => [
                'position' => $position, 'count' => $items->count(), 'average' => round((float) $items->whereNotNull('final_score')->avg('final_score'), 2),
            ])->values()->all()],
            'individual' => $this->individualRows($kpiIds, $filters['kpi_id'] ?? null),
            'target_actual' => $this->itemRows($kpiIds, false),
            'below_target' => $this->itemRows($kpiIds, true),
            'ranking' => [['Peringkat', 'KPI ID', 'Nomor Karyawan', 'Nama Karyawan', 'Jabatan', 'Skor Final', 'Tie-break', 'Koreksi Berjalan'], collect(app(RankingService::class)->forPeriod($period))->flatMap(fn ($rows) => $rows)->values()->all()],
            'workflow_completeness' => [['KPI ID', 'Nomor Karyawan', 'Nama Karyawan', 'Status', 'Progress', 'Dikirim', 'Disetujui', 'Dikunci'], collect($baseRows())->map(fn (array $row) => [
                'kpi_id' => $row['kpi_id'], 'employee_number' => $row['employee_number'], 'employee_name' => $row['employee_name'], 'status' => $row['status'],
                'progress' => $row['progress_percentage'], 'submitted_at' => $row['submitted_at'], 'approved_at' => $row['approved_at'], 'locked_at' => $row['locked_at'],
            ])->all()],
            'change_history' => [['Waktu', 'Aksi', 'KPI ID', 'Pelaku', 'Sebelum', 'Sesudah'], AuditEvent::with('actor')->where('subject_type', 'EmployeeKpi')->whereIn('subject_id', $kpiIds)
                ->orderBy('occurred_at')->get()->map(fn ($event) => ['occurred_at' => $event->occurred_at, 'action' => $event->action, 'kpi_id' => $event->subject_id,
                    'actor' => $event->actor?->name, 'before' => json_encode($event->before_json, JSON_UNESCAPED_UNICODE), 'after' => json_encode($event->after_json, JSON_UNESCAPED_UNICODE)])->all()],
            'import_reconciliation' => [['Batch', 'File', 'Cabang', 'Mata Uang', 'Status', 'Total', 'Valid', 'Peringatan', 'Error', 'Duplikat'], ImportBatch::with('branch')->where('period_id', $period->id)
                ->whereIn('branch_id', EmployeeKpi::whereIn('id', $kpiIds)->pluck('branch_id_snapshot'))->cursor()->map(fn ($batch) => [
                    'id' => $batch->id, 'file' => $batch->file_name, 'branch' => $batch->branch?->name, 'currency' => $batch->currency,
                    'status' => $batch->status, 'total' => $batch->total_rows, 'valid' => $batch->valid_rows, 'warnings' => $batch->warning_rows,
                    'errors' => $batch->error_rows, 'duplicates' => $batch->duplicate_rows,
                ])->all()],
            'coaching_action_plan' => [['Tanggal', 'Supervisor', 'Karyawan', 'Topik', 'Catatan', 'Target Tercapai', 'Tindak Lanjut'], CoachingLog::with(['supervisor', 'employee'])->where('period_id', $period->id)
                ->whereIn('employee_id', EmployeeKpi::whereIn('id', $kpiIds)->pluck('employee_id'))->get()->map(fn ($log) => [
                    'date' => $log->coaching_date?->toDateString(), 'supervisor' => $log->supervisor?->name, 'employee' => $log->employee?->name,
                    'topic' => $log->topic, 'notes' => $log->notes, 'target_met' => $log->target_met ? 'Ya' : 'Tidak', 'follow_up' => $log->follow_up_date?->toDateString(),
                ])->all()],
        };

        return ['type' => $type, 'period' => $period, 'headings' => $headings, 'rows' => $rows, 'formats' => self::FORMATS[$type]];
    }

    private function individualRows($kpiIds, mixed $kpiId): array
    {
        abort_unless($kpiId, 422, 'kpi_id wajib untuk laporan individual.');
        $kpi = EmployeeKpi::with('items')->whereIn('id', $kpiIds)->findOrFail($kpiId);
        $rows = $kpi->items->map(fn (EmployeeKpiItem $item) => [
            'code' => $item->definition_code_snapshot, 'name' => $item->name_snapshot, 'weight' => $item->weight_snapshot,
            'target' => $item->target_value_snapshot, 'unit' => $item->target_unit_snapshot, 'actual' => $item->actual_decimal,
            'achievement' => $item->achievement_percentage, 'weighted_score' => $item->weighted_score,
        ])->all();

        return [['Kode', 'Indikator', 'Bobot', 'Target', 'Unit', 'Aktual', 'Achievement', 'Skor Berbobot'], $rows];
    }

    private function itemRows($kpiIds, bool $belowTarget): array
    {
        $items = EmployeeKpiItem::query()->join('employee_kpis', 'employee_kpis.id', '=', 'employee_kpi_items.employee_kpi_id')
            ->whereIn('employee_kpis.id', $kpiIds)
            ->when($belowTarget, fn ($query) => $query->whereNotNull('achievement_percentage')->where('achievement_percentage', '<', 100))
            ->selectRaw('employee_kpis.employee_number_snapshot, employee_kpis.employee_name_snapshot, employee_kpi_items.definition_code_snapshot, employee_kpi_items.name_snapshot, employee_kpi_items.target_value_snapshot, employee_kpi_items.target_unit_snapshot, employee_kpi_items.actual_decimal, employee_kpi_items.achievement_percentage')
            ->cursor()->map(fn ($item) => [
                'employee_number' => $item->employee_number_snapshot, 'employee_name' => $item->employee_name_snapshot,
                'code' => $item->definition_code_snapshot, 'indicator' => $item->name_snapshot, 'target' => $item->target_value_snapshot,
                'unit' => $item->target_unit_snapshot, 'actual' => $item->actual_decimal, 'achievement' => $item->achievement_percentage,
            ])->all();

        return [['Nomor Karyawan', 'Nama Karyawan', 'Kode', 'Indikator', 'Target', 'Unit', 'Aktual', 'Achievement'], $items];
    }

    public function rowPayload(object $row, KpiPeriod $period): array
    {
        return [
            'kpi_id' => $row->kpi_id,
            'period' => $period->name,
            'employee_number' => $row->employee_number,
            'employee_name' => $row->employee_name,
            'position_name' => $row->position_name,
            'branch_name' => $row->branch_name,
            'status' => $row->status,
            'progress_percentage' => $row->progress_percentage !== null ? (float) $row->progress_percentage : null,
            'final_score' => $row->final_score !== null ? (float) $row->final_score : null,
            'rating_label' => $row->rating_label,
            'submitted_at' => $this->datePayload($row->submitted_at ?? null),
            'approved_at' => $this->datePayload($row->approved_at ?? null),
            'locked_at' => $this->datePayload($row->locked_at ?? null),
        ];
    }

    private function datePayload(mixed $value): ?string
    {
        return $value ? Carbon::parse((string) $value)->toIso8601String() : null;
    }
}
