<?php

namespace App\Modules\Assessment;

use App\Models\AuditEvent;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\KpiActualEntry;
use App\Models\KpiDailyEntry;
use App\Models\KpiPeriod;
use App\Models\SystemNotification;
use App\Models\User;
use App\Modules\Calculation\KpiCalculationEngine;
use App\Support\KpiWorkflow;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DailyAssessmentService
{
    public function __construct(
        protected KpiCalculationEngine $calculationEngine
    ) {}

    public function employeeDay(User $user, string $date): array
    {
        $period = $this->periodForDate($date);
        $employee = $user->employee;
        if (!$employee) {
            throw new Exception('Profil karyawan tidak ditemukan.');
        }

        $kpi = EmployeeKpi::with(['period', 'employee.position', 'employee.branch', 'items'])
            ->where('period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->first();
        if (!$kpi) {
            throw new Exception('Snapshot KPI Anda belum digenerate untuk periode ini.');
        }

        $entries = $this->ensureEntries($kpi, $date);

        return compact('date', 'period', 'kpi', 'entries');
    }

    public function saveEmployeeDay(
        User $user,
        string $date,
        array $items,
        bool $submit = false
    ): array {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'Karyawan tidak mengisi KPI harian. Nilai KPI ditentukan sistem, Supervisor, atau Manager.'
        );
    }

    public function supervisorQueue(User $user, string $date): Collection
    {
        $period = $this->periodForDate($date);
        $this->assertRole($user, 'supervisor');
        $this->ensureAssignedEntries($user, $period, $date, 'supervisor');

        return KpiDailyEntry::with([
            'item.employeeKpi.employee.position',
            'item.employeeKpi.employee.branch',
            'item.employeeKpi.period',
        ])
            ->whereDate('entry_date', $date)
            ->where('entry_status', 'submitted')
            ->whereIn('supervisor_status', ['pending', 'revision_required'])
            ->whereHas('item.employeeKpi', function ($query) use ($user, $period): void {
                $query->where('period_id', $period->id);
                if (!$user->hasRole('super_admin')) {
                    $query->where('supervisor_id_snapshot', $user->employee?->id);
                }
            })
            ->orderBy('id')
            ->get();
    }

    public function managerQueue(User $user, string $date): Collection
    {
        $period = $this->periodForDate($date);
        $this->assertRole($user, 'manager');
        $this->ensureAssignedEntries($user, $period, $date, 'manager');

        return KpiDailyEntry::with([
            'item.employeeKpi.employee.position',
            'item.employeeKpi.employee.branch',
            'item.employeeKpi.period',
        ])
            ->whereDate('entry_date', $date)
            ->where('entry_status', 'submitted')
            ->where('supervisor_status', 'approved')
            ->whereIn('manager_status', ['pending', 'revision_required', 'approved'])
            ->whereDoesntHave('item.employeeKpi.items.dailyEntries', function ($query) use ($date): void {
                $query->whereDate('entry_date', $date)
                    ->where(function ($statusQuery): void {
                        $statusQuery->where('entry_status', '!=', 'submitted')
                            ->orWhere('supervisor_status', '!=', 'approved');
                    });
            })
            ->whereHas('item.employeeKpi', function ($query) use ($user, $period): void {
                $query->where('period_id', $period->id);
                if (!$user->hasRole('super_admin')) {
                    $query->where('manager_id_snapshot', $user->employee?->id);
                }
            })
            ->orderBy('id')
            ->get();
    }

    public function assessSupervisor(
        User $user,
        int $entryId,
        string $decision,
        ?float $actualDecimal = null,
        ?array $actualJson = null,
        ?array $answers = null,
        ?string $note = null
    ): KpiDailyEntry {
        return $this->assess($user, $entryId, 'supervisor', $decision, $actualDecimal, $actualJson, $answers, $note);
    }

    public function assessManager(
        User $user,
        int $entryId,
        string $decision,
        ?float $actualDecimal = null,
        ?array $actualJson = null,
        ?array $answers = null,
        ?string $note = null
    ): KpiDailyEntry {
        return $this->assess($user, $entryId, 'manager', $decision, $actualDecimal, $actualJson, $answers, $note);
    }

    public function aggregateKpi(EmployeeKpi $kpi, ?int $userId = null): ?array
    {
        $kpi->load(['items.dailyEntries']);
        $changed = false;

        foreach ($kpi->items as $item) {
            $approved = $item->dailyEntries->where('manager_status', 'approved');
            $isDailyAggregate = is_array($item->actual_json) && !empty($item->actual_json['_daily_aggregate']);

            if ($approved->isEmpty()) {
                if ($isDailyAggregate) {
                    $item->actual_decimal = null;
                    $item->actual_json = [
                        '_daily_aggregate' => true,
                        'aggregation' => null,
                        'approved_days' => 0,
                    ];
                    $item->save();
                    $changed = true;
                }
                continue;
            }

            $isRubric = $item->formula_key_snapshot === 'rubric';
            $values = $approved->map(fn (KpiDailyEntry $entry) => $isRubric
                ? $entry->effectiveRubricScore()
                : $entry->effectiveActualDecimal()
            )->filter(fn ($value) => $value !== null)->map(fn ($value) => (float) $value);
            if ($values->isEmpty()) {
                continue;
            }

            $isAverage = $isRubric || in_array(strtolower((string) $item->target_unit_snapshot), ['%', 'persen'], true);
            $actual = $isAverage ? $values->avg() : $values->sum();
            $metadata = [
                '_daily_aggregate' => true,
                'aggregation' => $isAverage ? 'average' : 'sum',
                'approved_days' => $values->count(),
                'first_date' => $approved->min('entry_date')?->toDateString(),
                'last_date' => $approved->max('entry_date')?->toDateString(),
            ];

            $item->actual_decimal = round((float) $actual, 2);
            $item->actual_json = $metadata;
            $item->save();
            $changed = true;

            if ($userId) {
                KpiActualEntry::create([
                    'employee_kpi_item_id' => $item->id,
                    'input_by' => $userId,
                    'actual_value' => $item->actual_decimal,
                    'actual_json' => $metadata,
                    'notes' => 'Agregasi penilaian harian Manager.',
                ]);
            }
        }

        if (!$changed) {
            return null;
        }

        return $this->calculationEngine->calculateKpi($kpi, 'daily_aggregation', $userId);
    }

    private function assess(
        User $user,
        int $entryId,
        string $role,
        string $decision,
        ?float $actualDecimal,
        ?array $actualJson,
        ?array $answers,
        ?string $note
    ): KpiDailyEntry {
        if (!in_array($decision, ['approved', 'revision_required'], true)) {
            throw new Exception('Keputusan penilaian harian tidak valid.');
        }

        return DB::transaction(function () use ($user, $entryId, $role, $decision, $actualDecimal, $actualJson, $answers, $note): KpiDailyEntry {
            $entry = KpiDailyEntry::with([
                'item.employeeKpi.employee.user',
                'item.employeeKpi.employee.position',
                'item.employeeKpi.employee.branch',
                'item.employeeKpi.period',
                'item.employeeKpi.supervisorSnapshot.user',
                'item.employeeKpi.managerSnapshot.user',
            ])->whereKey($entryId)->lockForUpdate()->first();
            if (!$entry) {
                throw new Exception('Penilaian KPI harian tidak ditemukan.');
            }

            $kpi = $entry->item->employeeKpi;
            $this->assertRole($user, $role);
            $canAssess = $role === 'manager'
                ? KpiWorkflow::canManageKpi($user, $kpi)
                : KpiWorkflow::canReviewKpi($user, $kpi);
            if (!$canAssess) {
                throw new Exception('Anda tidak berwenang menilai KPI harian ini.');
            }
            KpiWorkflow::assertMutableKpi($kpi);
            $this->assertDailyWindow($kpi->period, $role);

            if ($entry->entry_status !== 'submitted') {
                throw new Exception('Entri harian belum siap untuk direview.');
            }
            if ($role === 'manager' && $entry->supervisor_status !== 'approved') {
                throw new Exception('Penilaian Supervisor harus disetujui terlebih dahulu.');
            }
            if ($decision === 'revision_required' && trim((string) $note) === '') {
                throw new Exception('Alasan revisi wajib diisi.');
            }

            $before = $this->entryAuditPayload($entry);
            $normalizedAnswers = null;
            $score = null;
            if ($decision === 'approved') {
                if ($entry->item->formula_key_snapshot === 'rubric') {
                    [$normalizedAnswers, $score] = $this->normalizeRubricAnswers($entry->item, $answers ?? []);
                } else {
                    if ($answers !== null && $answers !== []) {
                        throw new Exception('Indikator numerik tidak menerima checklist rubrik.');
                    }
                    $isSystemSource = $entry->item->isSystemSourced();
                    $fallback = $role === 'manager'
                        ? $entry->supervisor_actual_decimal
                        : null;
                    $actualDecimal = $actualDecimal ?? ($fallback !== null ? (float) $fallback : null);
                    if ($actualDecimal === null && (!$isSystemSource || $entry->item->systemActualDecimal() === null)) {
                        throw new Exception($isSystemSource
                            ? 'Nilai sistem belum tersedia. Sinkronkan data operasional terlebih dahulu.'
                            : 'Nilai aktual harian wajib diisi untuk indikator ini.');
                    }
                }
            }

            if ($role === 'supervisor') {
                $entry->supervisor_actual_decimal = $decision === 'approved' && $entry->item->formula_key_snapshot !== 'rubric' ? $actualDecimal : null;
                $entry->supervisor_actual_json = $decision === 'approved' ? $actualJson : null;
                $entry->supervisor_answers_json = $decision === 'approved' ? $normalizedAnswers : null;
                $entry->supervisor_score_percentage = $decision === 'approved' ? $score : null;
                $entry->supervisor_note = $note;
                $entry->supervisor_assessed_by = $user->id;
                $entry->supervisor_status = $decision;
                $entry->supervisor_assessed_at = now();
                $entry->manager_actual_decimal = null;
                $entry->manager_actual_json = null;
                $entry->manager_answers_json = null;
                $entry->manager_score_percentage = null;
                $entry->manager_note = null;
                $entry->manager_assessed_by = null;
                $entry->manager_status = 'pending';
                $entry->manager_assessed_at = null;
            } else {
                $entry->manager_actual_decimal = $decision === 'approved' && $entry->item->formula_key_snapshot !== 'rubric' ? $actualDecimal : null;
                $entry->manager_actual_json = $decision === 'approved' ? $actualJson : null;
                $entry->manager_answers_json = $decision === 'approved' ? $normalizedAnswers : null;
                $entry->manager_score_percentage = $decision === 'approved' ? $score : null;
                $entry->manager_note = $note;
                $entry->manager_assessed_by = $user->id;
                $entry->manager_status = $decision;
                $entry->manager_assessed_at = now();
            }
            $entry->row_version += 1;
            $entry->save();

            $this->aggregateKpi($kpi, $user->id);
            AuditEvent::log(
                action: $role === 'manager' ? 'assess_daily_kpi_manager' : 'assess_daily_kpi_supervisor',
                subjectType: 'KpiDailyEntry',
                subjectId: (string) $entry->id,
                before: $before,
                after: $this->entryAuditPayload($entry),
                reason: $note,
                actorId: $user->id
            );
            $this->notifyAssessmentResult($entry, $role, $decision);

            return $entry->fresh();
        });
    }

    private function normalizeRubricAnswers(EmployeeKpiItem $item, array $answers): array
    {
        $criteria = collect($item->rubric_snapshot['criteria'] ?? [])->keyBy(fn (array $criterion) => (string) $criterion['id']);
        if ($criteria->isEmpty()) {
            throw new Exception('Rubrik item belum memiliki kriteria yang valid.');
        }

        $answerIds = collect($answers)->map(fn (array $answer) => (string) ($answer['criterion_id'] ?? ''));
        if ($answerIds->duplicates()->isNotEmpty()) {
            throw new Exception('Kriteria rubrik tidak boleh dikirim dua kali.');
        }

        $normalized = collect($answers)->map(function (array $answer) use ($criteria): array {
            $criterion = $criteria->get((string) ($answer['criterion_id'] ?? ''));
            if (!$criterion) {
                throw new Exception('Kriteria rubrik tidak valid untuk item ini.');
            }

            return [
                'criterion_id' => $criterion['id'],
                'criterion_text' => $criterion['criterion_text'],
                'points' => (float) $criterion['points'],
                'is_fulfilled' => (bool) ($answer['is_fulfilled'] ?? false),
                'notes' => $answer['notes'] ?? null,
            ];
        })->values();

        if ($criteria->keys()->diff($normalized->pluck('criterion_id')->map(fn ($id) => (string) $id))->isNotEmpty()) {
            throw new Exception('Semua kriteria rubrik wajib dinilai.');
        }

        $totalPoints = (float) $normalized->sum('points');
        $earnedPoints = (float) $normalized->where('is_fulfilled', true)->sum('points');
        $score = $totalPoints > 0 ? min(($earnedPoints / $totalPoints) * 100, 100) : 0;

        return [$normalized->all(), round($score, 2)];
    }

    private function ensureEntries(EmployeeKpi $kpi, string $date): Collection
    {
        foreach ($kpi->items as $item) {
            KpiDailyEntry::firstOrCreate([
                'employee_kpi_item_id' => $item->id,
                'entry_date' => $date,
            ], [
                'entry_status' => 'submitted',
            ]);
        }

        $this->notifySupervisor($kpi, $date);

        return KpiDailyEntry::with('item')
            ->whereIn('employee_kpi_item_id', $kpi->items->pluck('id'))
            ->whereDate('entry_date', $date)
            ->orderBy('id')
            ->get();
    }

    private function ensureAssignedEntries(User $user, KpiPeriod $period, string $date, string $role): void
    {
        $query = EmployeeKpi::with([
            'period',
            'employee.position',
            'employee.branch',
            'items',
            'supervisorSnapshot.user',
        ])->where('period_id', $period->id);

        if (!$user->hasRole('super_admin')) {
            $assignmentColumn = $role === 'manager' ? 'manager_id_snapshot' : 'supervisor_id_snapshot';
            $query->where($assignmentColumn, $user->employee?->id);
        }

        $query->get()->each(fn (EmployeeKpi $kpi) => $this->ensureEntries($kpi, $date));
    }

    private function periodForDate(string $date): KpiPeriod
    {
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            throw new Exception('Tanggal KPI harian harus menggunakan format YYYY-MM-DD.');
        }
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $parsed->isFuture()) {
            throw new Exception('Tanggal KPI harian tidak valid atau belum terjadi.');
        }

        $period = KpiPeriod::where('status', 'OPEN')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->orderByDesc('id')
            ->first();
        if (!$period) {
            throw new Exception('Tidak ada periode KPI terbuka untuk tanggal tersebut.');
        }

        return $period;
    }

    private function assertDailyWindow(KpiPeriod $period, string $role): void
    {
        if ($period->status !== 'OPEN') {
            throw new Exception('Periode KPI harian tidak sedang terbuka.');
        }

        $deadline = $role === 'manager' ? $period->approval_deadline : $period->review_deadline;
        if ($deadline?->isPast()) {
            throw new Exception('Batas waktu penilaian KPI harian telah berakhir.');
        }
    }

    private function assertRole(User $user, string $role): void
    {
        $allowed = $role === 'manager' ? ['owner_manager', 'super_admin'] : ['supervisor', 'super_admin'];
        if (!$user->hasAnyRole($allowed)) {
            throw new Exception('Peran Anda tidak dapat melakukan tindakan ini.');
        }
    }

    private function resetAssessments(KpiDailyEntry $entry): void
    {
        $entry->supervisor_actual_decimal = null;
        $entry->supervisor_actual_json = null;
        $entry->supervisor_answers_json = null;
        $entry->supervisor_score_percentage = null;
        $entry->supervisor_note = null;
        $entry->supervisor_assessed_by = null;
        $entry->supervisor_status = 'pending';
        $entry->supervisor_assessed_at = null;
        $entry->manager_actual_decimal = null;
        $entry->manager_actual_json = null;
        $entry->manager_answers_json = null;
        $entry->manager_score_percentage = null;
        $entry->manager_note = null;
        $entry->manager_assessed_by = null;
        $entry->manager_status = 'pending';
        $entry->manager_assessed_at = null;
    }

    private function entryAuditPayload(KpiDailyEntry $entry): array
    {
        return [
            'entry_date' => $entry->entry_date?->toDateString(),
            'entry_status' => $entry->entry_status,
            'employee_actual_decimal' => $entry->employee_actual_decimal,
            'supervisor_actual_decimal' => $entry->supervisor_actual_decimal,
            'supervisor_score_percentage' => $entry->supervisor_score_percentage,
            'supervisor_status' => $entry->supervisor_status,
            'manager_actual_decimal' => $entry->manager_actual_decimal,
            'manager_score_percentage' => $entry->manager_score_percentage,
            'manager_status' => $entry->manager_status,
        ];
    }

    private function notifySupervisor(EmployeeKpi $kpi, string $date): void
    {
        if ($kpi->supervisorSnapshot?->user_id) {
            $body = "Data KPI harian {$date} dari {$kpi->employee->name} siap divalidasi.";
            $alreadyNotified = SystemNotification::query()
                ->where('user_id', $kpi->supervisorSnapshot->user_id)
                ->where('type', 'daily_kpi_submitted')
                ->where('entity_type', 'EmployeeKpi')
                ->where('entity_id', (string) $kpi->id)
                ->where('body', $body)
                ->exists();
            if ($alreadyNotified) {
                return;
            }

            SystemNotification::send(
                userId: $kpi->supervisorSnapshot->user_id,
                title: "KPI Harian Menunggu Review: {$kpi->employee->name}",
                body: $body,
                type: 'daily_kpi_submitted',
                entityType: 'EmployeeKpi',
                entityId: (string) $kpi->id,
                actionUrl: "/app/supervisor-daily-assessments?date={$date}"
            );
        }
    }

    private function notifyAssessmentResult(KpiDailyEntry $entry, string $role, string $decision): void
    {
        $kpi = $entry->item->employeeKpi;
        if ($role === 'supervisor' && $decision === 'approved' && $kpi->managerSnapshot?->user_id) {
            $date = $entry->entry_date->toDateString();
            $expectedItems = $kpi->items()->count();
            $approvedItems = KpiDailyEntry::whereHas('item', fn ($query) => $query->where('employee_kpi_id', $kpi->id))
                ->whereDate('entry_date', $date)
                ->where('supervisor_status', 'approved')
                ->count();

            if ($expectedItems > 0 && $approvedItems === $expectedItems) {
                $body = "Review Supervisor untuk {$date} selesai. Silakan nilai atau koreksi seluruh indikator.";
                $alreadyNotified = SystemNotification::query()
                    ->where('user_id', $kpi->managerSnapshot->user_id)
                    ->where('type', 'daily_kpi_reviewed')
                    ->where('entity_type', 'EmployeeKpi')
                    ->where('entity_id', (string) $kpi->id)
                    ->where('body', $body)
                    ->exists();

                if (!$alreadyNotified) {
                    SystemNotification::send(
                        userId: $kpi->managerSnapshot->user_id,
                        title: "KPI Harian Menunggu Penilaian: {$kpi->employee->name}",
                        body: $body,
                        type: 'daily_kpi_reviewed',
                        entityType: 'EmployeeKpi',
                        entityId: (string) $kpi->id,
                        actionUrl: "/app/manager-daily-assessments?date={$date}"
                    );
                }
            }
        }

        if ($role === 'manager' || $decision === 'revision_required') {
            $employeeUserId = $kpi->employee?->user_id;
            if ($employeeUserId) {
                SystemNotification::send(
                    userId: $employeeUserId,
                    title: $decision === 'approved' ? 'KPI Harian Disetujui Manager' : 'KPI Harian Perlu Revisi',
                    body: "Penilaian KPI harian {$entry->entry_date->toDateString()} untuk {$entry->item->name_snapshot} telah diperbarui.",
                    type: $decision === 'approved' ? 'daily_kpi_approved' : 'daily_kpi_revision_required',
                    entityType: 'KpiDailyEntry',
                    entityId: (string) $entry->id,
                    actionUrl: "/app/my-kpi/daily?date={$entry->entry_date->toDateString()}"
                );
            }
        }
    }
}
