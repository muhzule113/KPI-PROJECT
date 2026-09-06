<?php

namespace App\Modules\Reporting;

use App\Models\AdminWorkLog;
use App\Models\AuditEvent;
use App\Models\EmployeeKpi;
use App\Models\KpiPeriod;
use App\Models\ReportSubmission;
use App\Models\User;
use App\Modules\Calculation\KpiCalculationEngine;
use App\Support\KpiWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReportSubmissionService
{
    public function __construct(private KpiCalculationEngine $calculationEngine) {}

    public function schedule(KpiPeriod $period): int
    {
        $created = 0;
        foreach ($period->employeeKpis()->get() as $kpi) {
            $types = match ($kpi->position_code_snapshot) {
                'POS-ADM' => ['admin_daily', 'admin_monthly'],
                'POS-SPV' => ['supervisor_monthly'],
                default => [],
            };
            foreach ($types as $type) {
                $dates = $type === 'admin_daily'
                    ? collect($this->weekdays($period))
                    : collect([$period->end_date->copy()]);
                foreach ($dates as $date) {
                    $deadline = $type === 'admin_daily'
                        ? $date->copy()->setTimezone('Asia/Makassar')->setTime(18, 0)
                        : $period->review_deadline->copy();
                    $submission = ReportSubmission::firstOrCreate([
                        'period_id' => $period->id,
                        'employee_id' => $kpi->employee_id,
                        'report_type' => $type,
                        'report_date' => $date->toDateString(),
                    ], [
                        'deadline_at' => $deadline,
                        'status' => 'scheduled',
                        'source' => 'system',
                    ]);
                    $created += $submission->wasRecentlyCreated ? 1 : 0;
                }
            }
        }

        return $created;
    }

    public function submit(ReportSubmission $submission, User $actor): ReportSubmission
    {
        $employee = $actor->employee;
        if (! $employee || (string) $employee->id !== (string) $submission->employee_id) {
            throw new AuthorizationException('Laporan hanya dapat disubmit oleh karyawan yang dijadwalkan.');
        }
        if ($submission->submitted_at) {
            throw new RuntimeException('Laporan sudah disubmit; perubahan wajib melalui alur koreksi.');
        }
        if ($submission->period?->isLocked()) {
            throw new RuntimeException('Periode terkunci dan tidak dapat diubah.');
        }
        if ($submission->report_type === 'admin_daily'
            && ! AdminWorkLog::where('period_id', $submission->period_id)->where('employee_id', $submission->employee_id)
                ->whereDate('work_date', $submission->report_date)->exists()) {
            throw new RuntimeException('Work-log pada tanggal laporan belum tersedia.');
        }
        if ($submission->report_type === 'supervisor_monthly') {
            $unfinished = EmployeeKpi::where('period_id', $submission->period_id)
                ->where('supervisor_id_snapshot', $submission->employee_id)
                ->where('eligibility', 'full')
                ->whereNotIn('status', ['pending_approval', 'approved', 'locked'])->exists();
            if ($unfinished) {
                throw new RuntimeException('Seluruh KPI tim eligible harus diteruskan ke Manager terlebih dahulu.');
            }
        }

        return DB::transaction(function () use ($submission, $actor): ReportSubmission {
            $submission = ReportSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            $now = now();
            $snapshot = match ($submission->report_type) {
                'admin_daily' => AdminWorkLog::where('period_id', $submission->period_id)
                    ->where('employee_id', $submission->employee_id)->whereDate('work_date', $submission->report_date)->first()?->toArray(),
                'admin_monthly' => AdminWorkLog::where('period_id', $submission->period_id)
                    ->where('employee_id', $submission->employee_id)->get()->toArray(),
                default => EmployeeKpi::where('period_id', $submission->period_id)
                    ->where('supervisor_id_snapshot', $submission->employee_id)->get(['id', 'status', 'final_score'])->toArray(),
            };
            $submission->update([
                'submitted_at' => $now,
                'is_on_time' => $now->lessThanOrEqualTo($submission->deadline_at),
                'status' => 'submitted',
                'content_snapshot' => $snapshot,
                'submitted_by' => $actor->id,
            ]);
            AuditEvent::log('report_submitted', 'ReportSubmission', (string) $submission->id,
                after: ['report_type' => $submission->report_type, 'submitted_at' => $now, 'is_on_time' => $submission->is_on_time], actorId: $actor->id);
            $this->syncPeriod($submission->period);

            return $submission->fresh();
        });
    }

    public function syncPeriod(KpiPeriod $period): array
    {
        $updated = 0;
        foreach ($period->employeeKpis()->with('items')->get() as $kpi) {
            if (! KpiWorkflow::canSystemSyncKpi($kpi)) {
                continue;
            }
            $item = $kpi->items->firstWhere('definition_code_snapshot', $kpi->position_code_snapshot === 'POS-ADM' ? 'ADM-02' : 'SUP-07');
            if (! $item || ! in_array($kpi->position_code_snapshot, ['POS-ADM', 'POS-SPV'], true)) {
                continue;
            }
            $submissions = ReportSubmission::where('period_id', $period->id)->where('employee_id', $kpi->employee_id)->get();
            $actual = $kpi->position_code_snapshot === 'POS-ADM'
                ? $this->adminScore($submissions)
                : $this->supervisorScore($submissions);
            $item->actual_decimal = $actual;
            $item->actual_json = ['_system_calculated' => true, 'submissions' => $submissions->map->only(['id', 'report_type', 'deadline_at', 'submitted_at', 'is_on_time'])->all()];
            $item->status = $actual === null ? 'not_started' : 'verified';
            $item->save();
            $this->calculationEngine->calculateItem($item);
            $updated++;
        }

        return ['updated_items' => $updated];
    }

    private function adminScore($submissions): ?float
    {
        $daily = $submissions->where('report_type', 'admin_daily')
            ->filter(fn (ReportSubmission $row): bool => $row->submitted_at !== null || $row->deadline_at->isPast());
        $monthly = $submissions->firstWhere('report_type', 'admin_monthly');
        $parts = [];
        if ($daily->isNotEmpty()) {
            $parts[] = [70, $daily->where('is_on_time', true)->count() / $daily->count() * 100];
        }
        if ($monthly && ($monthly->submitted_at || $monthly->deadline_at->isPast())) {
            $parts[] = [30, $monthly->is_on_time ? 100 : 0];
        }
        if (! $parts) {
            return null;
        }
        $weight = array_sum(array_column($parts, 0));

        return round(array_sum(array_map(fn (array $part): float => $part[0] * $part[1], $parts)) / $weight, 6, PHP_ROUND_HALF_UP);
    }

    private function supervisorScore($submissions): ?float
    {
        $monthly = $submissions->firstWhere('report_type', 'supervisor_monthly');
        if (! $monthly || (! $monthly->submitted_at && ! $monthly->deadline_at->isPast())) {
            return null;
        }

        return $monthly->is_on_time ? 100.0 : 0.0;
    }

    private function weekdays(KpiPeriod $period): array
    {
        $dates = [];
        for ($date = $period->start_date->copy(); $date->lte($period->end_date); $date->addDay()) {
            if ($date->isWeekday()) {
                $dates[] = $date->copy();
            }
        }

        return $dates;
    }
}
