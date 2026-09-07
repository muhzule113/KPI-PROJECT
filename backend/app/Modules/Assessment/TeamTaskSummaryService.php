<?php

namespace App\Modules\Assessment;

use App\Models\Attendance;
use App\Models\EmployeeKpi;
use App\Models\KpiDailyEntry;
use App\Models\KpiPeriod;
use App\Models\User;
use App\Modules\Approval\ApprovalService;
use App\Modules\Review\ReviewService;
use App\Support\CapabilityMatrix;
use App\Support\KpiWorkflow;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class TeamTaskSummaryService
{
    public function __construct(
        protected ReviewService $reviewService,
        protected ApprovalService $approvalService,
    ) {}

    public function summary(User $user, ?string $date = null): array
    {
        if ($date !== null) {
            try {
                $parsed = Carbon::createFromFormat('!Y-m-d', $date);
            } catch (\Throwable) {
                throw new Exception('Tanggal tugas harus menggunakan format YYYY-MM-DD dan tidak boleh di masa depan.');
            }
            if (! $parsed || $parsed->format('Y-m-d') !== $date || $parsed->isFuture()) {
                throw new Exception('Tanggal tugas harus menggunakan format YYYY-MM-DD dan tidak boleh di masa depan.');
            }
        }

        $role = CapabilityMatrix::has($user, 'kpi.manager.approval') ? 'manager'
            : (CapabilityMatrix::has($user, 'kpi.supervisor.review') ? 'supervisor' : null);
        if ($role === null) {
            throw new Exception('Ruang kerja tim hanya tersedia untuk Supervisor dan Manager.');
        }

        $periods = KpiPeriod::query()
            ->whereIn('status', ['OPEN', 'SUBMISSION_CLOSED', 'IN_REVIEW', 'WAITING_APPROVAL'])
            ->orderBy('start_date')
            ->get();
        $assignedKpis = $this->assignedKpis($user, $periods, $role);
        $daily = $this->dailyTasks($assignedKpis, $role, $date);
        $required = $daily['required'];
        $optional = $daily['optional'];
        $prepared = $daily['prepared'];

        if ($date === null) {
            $monthly = $this->monthlyTasks($user, $assignedKpis, $role);
            $required = $required->concat($monthly);
            $prepared = $prepared->concat($this->monthlyPreparedTasks($assignedKpis, $role));
        }

        $employees = $required
            ->groupBy('employee.id')
            ->map(function (Collection $tasks) use ($optional): array {
                $requiredTasks = $this->sortTasks($tasks);
                $optionalTasks = $this->sortTasks($optional->where('employee.id', $tasks->first()['employee']['id'])->values());
                $first = $tasks->first();

                return [
                    'employee' => $first['employee'],
                    'periods' => $tasks->pluck('period')->unique('id')->values()->all(),
                    'required_count' => $requiredTasks->count(),
                    'optional_count' => $optionalTasks->count(),
                    'oldest_task_date' => $requiredTasks->concat($optionalTasks)->pluck('date')->filter()->sort()->first(),
                    'status' => $requiredTasks->first()['status_label'],
                    'required_tasks' => $requiredTasks->all(),
                    'optional_tasks' => $optionalTasks->all(),
                    'primary_action' => $requiredTasks->first()['action'],
                    'secondary_actions' => [],
                ];
            })
            ->sortBy(fn (array $employee): array => [
                $employee['required_count'] === 0 ? 1 : 0,
                $employee['required_tasks'][0]['priority'] ?? 99,
                $employee['required_tasks'][0]['deadline'] ?? '9999-12-31',
                $employee['employee']['name'] ?? '',
            ])->values();

        $pendingEmployeeIds = $employees->pluck('employee.id')->map(fn ($id): string => (string) $id);
        $completedEmployees = $prepared
            ->reject(fn (array $task): bool => $pendingEmployeeIds->contains((string) $task['employee']['id']))
            ->groupBy('employee.id')
            ->map(function (Collection $tasks): array {
                $first = $tasks->first();

                return [
                    'employee' => $first['employee'],
                    'periods' => $tasks->pluck('period')->unique('id')->values()->all(),
                    'required_count' => 0,
                    'optional_count' => 0,
                    'oldest_task_date' => null,
                    'status' => 'Semua tindakan wajib sudah selesai.',
                    'required_tasks' => [],
                    'optional_tasks' => [],
                    'primary_action' => null,
                    'secondary_actions' => [],
                ];
            })->sortBy('employee.name')->values();

        $requiredCount = $employees->sum('required_count');
        $optionalCount = $optional->count();

        return [
            'role' => $role,
            'title' => 'Penilaian Tim',
            'date' => $date,
            'required_count' => $requiredCount,
            'optional_count' => $optionalCount,
            'employees' => $employees->all(),
            'completed_employees' => $completedEmployees->all(),
            'empty_state' => $this->emptyState($periods, $assignedKpis, $employees->concat($completedEmployees), $date),
        ];
    }

    private function assignedKpis(User $user, Collection $periods, string $role): Collection
    {
        if ($periods->isEmpty()) {
            return collect();
        }

        return EmployeeKpi::with([
            'employee.position', 'employee.branch', 'positionSnapshot', 'branchSnapshot', 'period', 'items.dailyEntries',
        ])->whereIn('period_id', $periods->pluck('id'))->get()
            ->filter(fn (EmployeeKpi $kpi): bool => $role === 'manager'
                ? KpiWorkflow::canManageKpi($user, $kpi)
                : KpiWorkflow::canReviewKpi($user, $kpi))
            ->values();
    }

    private function dailyTasks(Collection $kpis, string $role, ?string $date): array
    {
        $required = collect();
        $optional = collect();
        $prepared = collect();
        $attendances = Attendance::query()
            ->whereIn('employee_id', $kpis->pluck('employee_id'))
            ->when($date, fn ($query, string $value) => $query->whereDate('attendance_date', $value))
            ->get(['employee_id', 'attendance_date'])
            ->mapWithKeys(fn (Attendance $attendance): array => [
                $attendance->employee_id.'-'.$attendance->attendance_date->toDateString() => true,
            ]);

        foreach ($kpis as $kpi) {
            $deadline = $role === 'manager' ? $kpi->period->approval_deadline : $kpi->period->review_deadline;
            if ($deadline?->isPast()) {
                continue;
            }
            $entries = $kpi->items->flatMap->dailyEntries
                ->when($date, fn (Collection $rows) => $rows->filter(
                    fn (KpiDailyEntry $entry): bool => $entry->entry_date?->toDateString() === $date
                ))
                ->filter(fn (KpiDailyEntry $entry): bool => in_array($entry->entry_status, ['submitted', 'revision_required'], true));

            if (($role === 'supervisor' || $kpi->isSupervisorKpi()) && $entries->isNotEmpty()) {
                foreach ($entries->groupBy(fn (KpiDailyEntry $entry): string => $entry->entry_date->toDateString()) as $entryDate => $dayEntries) {
                    $prepared->push($this->preparedTask($kpi, $entryDate));
                }
            }

            if ($role === 'manager' && ! $kpi->isSupervisorKpi()) {
                $entries = $entries->filter(fn (KpiDailyEntry $entry): bool => $entry->supervisor_status === 'approved'
                    && in_array($entry->manager_status, ['pending', 'revision_required'], true));
                $target = $optional;
                $isOptional = true;
            } else {
                $statusColumn = $role === 'manager' ? 'manager_status' : 'supervisor_status';
                $entries = $entries->filter(fn (KpiDailyEntry $entry): bool => in_array($entry->{$statusColumn}, ['pending', 'revision_required'], true));
                $target = $required;
                $isOptional = false;
            }

            foreach ($entries->groupBy(fn (KpiDailyEntry $entry): string => $entry->entry_date->toDateString()) as $entryDate => $dayEntries) {
                $buckets = $dayEntries->groupBy(function (KpiDailyEntry $entry) use ($role, $isOptional, $attendances, $kpi): string {
                    $status = $role === 'manager' ? $entry->manager_status : $entry->supervisor_status;
                    if ($status === 'revision_required' || $entry->entry_status === 'revision_required') {
                        return 'revision';
                    }
                    if (! $isOptional && $entry->item->isAttendanceIndicator()
                        && ! $attendances->has($kpi->employee_id.'-'.$entry->entry_date->toDateString())) {
                        return 'attendance';
                    }

                    return $isOptional ? 'staff_daily_review' : 'daily_assessment';
                });

                foreach ($buckets as $type => $bucket) {
                    $target->push($this->dailyTask($kpi, $bucket, $role, $type, $entryDate, $isOptional));
                }
            }
        }

        return ['required' => $required, 'optional' => $optional, 'prepared' => $prepared];
    }

    private function dailyTask(EmployeeKpi $kpi, Collection $entries, string $role, string $type, string $date, bool $optional): array
    {
        $automaticCount = $role === 'supervisor' ? $entries->filter(
            fn (KpiDailyEntry $entry): bool => $entry->item->isSystemSourced() && ! $entry->item->isAttendanceIndicator()
        )->count() : 0;
        $url = ($role === 'manager' ? '/app/manager-daily-assessments' : '/app/supervisor-daily-assessments')
            .'?'.http_build_query(['date' => $date, 'kpi_id' => (string) $kpi->id]);
        $label = match ($type) {
            'revision' => 'Periksa masalah',
            'attendance' => 'Isi kehadiran',
            'staff_daily_review' => 'Tinjau staf',
            default => 'Nilai sekarang',
        };

        return $this->task($kpi, [
            'type' => $type,
            'date' => $date,
            'deadline' => ($role === 'manager' ? $kpi->period->approval_deadline : $kpi->period->review_deadline)?->toIso8601String(),
            'indicator_count' => $entries->count(),
            'automatic_indicator_count' => $automaticCount,
            'status' => $type === 'revision' ? 'revision_required' : 'pending',
            'status_label' => match ($type) {
                'revision' => 'Ada data yang perlu diperiksa.',
                'attendance' => 'Kehadiran belum diisi.',
                'staff_daily_review' => 'Tinjauan harian opsional tersedia.',
                default => 'Penilaian harian belum selesai.',
            },
            'optional' => $optional,
            'priority' => match ($type) {
                'revision' => 0,
                'attendance' => 1,
                'staff_daily_review' => 4,
                default => 2,
            },
            'action' => ['label' => $label, 'url' => $url, 'type' => $type],
        ]);
    }

    private function monthlyTasks(User $user, Collection $assignedKpis, string $role): Collection
    {
        $assigned = $assignedKpis->keyBy(fn (EmployeeKpi $kpi): string => (string) $kpi->id);
        $queue = $role === 'manager'
            ? $this->approvalService->getApprovalQueue($user)
            : $this->reviewService->getReviewQueue($user->id);

        return $queue->filter(fn (EmployeeKpi $kpi): bool => $assigned->has((string) $kpi->id)
                && ! in_array($kpi->period?->status, ['PUBLISHED', 'LOCKED', 'CANCELLED'], true)
                && ! ($role === 'manager' ? $kpi->period?->approval_deadline : $kpi->period?->review_deadline)?->isPast())
            ->map(function (EmployeeKpi $kpi) use ($role): array {
                $revision = $kpi->status === 'revision_required';
                $type = $role === 'manager' ? 'monthly_approval' : 'monthly_review';

                return $this->task($kpi, [
                    'type' => $revision ? 'revision' : $type,
                    'date' => null,
                    'deadline' => ($role === 'manager' ? $kpi->period->approval_deadline : $kpi->period->review_deadline)?->toIso8601String(),
                    'indicator_count' => $kpi->items->count(),
                    'automatic_indicator_count' => 0,
                    'status' => $kpi->status,
                    'status_label' => $revision ? 'Ada data yang perlu diperiksa.' : ($role === 'manager' ? 'Hasil bulanan siap disahkan.' : 'Rekap bulanan siap ditinjau.'),
                    'optional' => false,
                    'priority' => $revision ? 0 : 3,
                    'action' => [
                        'label' => $revision ? 'Periksa masalah' : ($role === 'manager' ? 'Sahkan hasil' : 'Tinjau rekap'),
                        'url' => $role === 'manager'
                            ? "/app/employee-kpis/{$kpi->id}/assessment"
                            : "/app/supervisor-reviews/{$kpi->id}/review",
                        'type' => $type,
                    ],
                ]);
            })->values();
    }

    private function monthlyPreparedTasks(Collection $kpis, string $role): Collection
    {
        return $kpis->filter(function (EmployeeKpi $kpi) use ($role): bool {
            if ($kpi->status === 'draft') {
                return false;
            }

            return $role === 'supervisor'
                || $kpi->isSupervisorKpi()
                || in_array($kpi->status, ['pending_approval', 'approved', 'locked'], true);
        })->map(fn (EmployeeKpi $kpi): array => $this->preparedTask($kpi, null))->values();
    }

    private function preparedTask(EmployeeKpi $kpi, ?string $date): array
    {
        return $this->task($kpi, ['date' => $date]);
    }

    private function task(EmployeeKpi $kpi, array $task): array
    {
        return [
            ...$task,
            'kpi_id' => (string) $kpi->id,
            'employee' => [
                'id' => (string) $kpi->employee_id,
                'name' => $kpi->employee_name_snapshot ?: $kpi->employee?->name,
                'position' => $kpi->positionSnapshot?->name ?? $kpi->employee?->position?->name,
                'branch' => $kpi->branchSnapshot?->name ?? $kpi->employee?->branch?->name,
            ],
            'period' => [
                'id' => $kpi->period->id,
                'name' => $kpi->period->name,
                'start_date' => $kpi->period->start_date?->toDateString(),
                'end_date' => $kpi->period->end_date?->toDateString(),
            ],
        ];
    }

    private function sortTasks(Collection $tasks): Collection
    {
        return $tasks->sortBy(fn (array $task): array => [
            $task['priority'],
            $task['deadline'] ?? '9999-12-31',
            $task['date'] ?? '9999-12-31',
        ])->values();
    }

    private function emptyState(Collection $periods, Collection $kpis, Collection $employees, ?string $date): ?array
    {
        if ($employees->isNotEmpty()) {
            return null;
        }
        if ($periods->isEmpty()) {
            return ['code' => 'no_active_period', 'title' => 'Tidak ada periode aktif', 'description' => 'Belum ada periode KPI yang dapat ditindaklanjuti.'];
        }
        if ($kpis->isEmpty()) {
            return ['code' => 'no_assignment', 'title' => 'Belum ada penugasan', 'description' => 'Tidak ada karyawan yang ditugaskan kepada Anda pada periode ini.'];
        }
        $hasEntries = $kpis->contains(fn (EmployeeKpi $kpi): bool => $kpi->items->contains(
            fn ($item): bool => $item->dailyEntries->when($date, fn (Collection $entries) => $entries->filter(
                fn (KpiDailyEntry $entry): bool => $entry->entry_date?->toDateString() === $date
            ))->isNotEmpty()
        ));

        return $hasEntries
            ? ['code' => 'complete', 'title' => 'Semua pekerjaan selesai', 'description' => 'Tidak ada tugas wajib atau tinjauan opsional yang masih menunggu.']
            : ['code' => 'not_prepared', 'title' => 'Data belum disiapkan', 'description' => 'Jadwal persiapan KPI belum membuat data harian untuk pilihan ini.'];
    }
}
