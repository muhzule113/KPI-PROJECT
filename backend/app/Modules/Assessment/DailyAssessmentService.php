<?php

namespace App\Modules\Assessment;

use App\Models\Attendance;
use App\Models\AuditEvent;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\KpiActualEntry;
use App\Models\KpiDailyEntry;
use App\Models\KpiPeriod;
use App\Models\SystemNotification;
use App\Models\User;
use App\Modules\Calculation\KpiCalculationEngine;
use App\Support\CapabilityMatrix;
use App\Support\KpiWorkflow;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DailyAssessmentService
{
    public function __construct(
        protected KpiCalculationEngine $calculationEngine,
        protected OperationalKpiSyncService $operationalSync
    ) {}

    public function preparePeriod(KpiPeriod $period, ?string $throughDate = null): int
    {
        return DB::transaction(function () use ($period, $throughDate): int {
            $this->operationalSync->syncPeriodOperationalData($period);
            $end = $period->end_date->copy()->min(Carbon::parse($throughDate ?? now()->toDateString())->startOfDay());
            $kpis = $period->employeeKpis()->with(['items', 'employee.position', 'supervisorSnapshot', 'managerSnapshot'])->get();
            $prepared = 0;
            foreach ($kpis as $kpi) {
                if (! KpiWorkflow::canSystemSyncKpi($kpi)) {
                    continue;
                }
                for ($date = $period->start_date->copy(); $date->lte($end); $date->addDay()) {
                    $this->ensureEntries($kpi, $date->toDateString());
                }
                $this->aggregateKpi($kpi);
                $prepared++;
            }

            return $prepared;
        });
    }

    public function employeeDay(User $user, string $date): array
    {
        $period = $this->periodForDate($date);
        $employee = $user->employee;
        if (! $employee) {
            throw new Exception('Profil karyawan tidak ditemukan.');
        }

        $kpi = EmployeeKpi::with(['period', 'employee.position', 'employee.branch', 'items'])
            ->where('period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->first();
        if (! $kpi) {
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
        $period = $this->periodForDate($date);
        $employee = $user->employee;
        if (! $employee) {
            throw new Exception('Profil karyawan tidak ditemukan.');
        }

        $kpi = EmployeeKpi::with(['period', 'employee', 'items.evidences'])
            ->where('period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->first();
        if (! $kpi) {
            throw new Exception('Snapshot KPI Anda belum digenerate untuk periode ini.');
        }
        if (! KpiWorkflow::canEmployeeWriteKpi($user, $kpi)) {
            throw new AuthorizationException('Pengisian dan submit KPI harian dilakukan oleh Supervisor. Karyawan hanya dapat melihat hasilnya.');
        }
        KpiWorkflow::assertMutableKpi($kpi);
        if (! in_array($kpi->status, ['draft', 'submitted', 'revision_required'], true)) {
            throw new Exception("KPI berstatus '{$kpi->status}' dan tidak dapat diedit.");
        }
        if ($period->status !== 'OPEN' || $period->submission_deadline->isPast()) {
            throw new Exception('Batas waktu pengisian periode ini telah berakhir.');
        }

        $entryMap = KpiDailyEntry::whereIn('employee_kpi_item_id', $kpi->items->pluck('id'))
            ->whereDate('entry_date', $date)
            ->get()
            ->keyBy('employee_kpi_item_id');
        $payload = collect($items)->values();

        DB::transaction(function () use ($user, $date, $submit, $kpi, $entryMap, $payload): void {
            foreach ($payload as $itemData) {
                if (! is_array($itemData)) {
                    throw new Exception('Format item KPI harian tidak valid.');
                }

                $itemId = (string) ($itemData['item_id'] ?? $itemData['id'] ?? '');
                $item = $kpi->items->first(fn (EmployeeKpiItem $candidate): bool => (string) $candidate->id === $itemId);
                if (! $item) {
                    throw new AuthorizationException('Indikator KPI bukan milik Anda.');
                }
                if (strtolower((string) $item->source_type_snapshot) !== 'employee') {
                    throw new Exception("Indikator {$item->definition_code_snapshot} diisi oleh sumber resmi lain.");
                }
                if ($item->formula_key_snapshot === 'rubric') {
                    throw new Exception("Indikator {$item->definition_code_snapshot} dinilai Supervisor melalui rubrik.");
                }
                if (array_key_exists('actual_decimal', $itemData)
                    && $itemData['actual_decimal'] !== null
                    && (! is_numeric($itemData['actual_decimal']) || ! is_finite((float) $itemData['actual_decimal']))) {
                    throw new Exception('Nilai aktual harus berupa angka yang valid.');
                }
                if (array_key_exists('actual_json', $itemData)
                    && $itemData['actual_json'] !== null
                    && ! is_array($itemData['actual_json'])) {
                    throw new Exception('Rincian nilai aktual harus berupa object/array.');
                }

                $entry = $entryMap->get($item->id) ?? new KpiDailyEntry([
                    'employee_kpi_item_id' => $item->id,
                    'entry_date' => $date,
                    'entry_status' => 'draft',
                ]);
                if ($entry->supervisor_status === 'approved' || $entry->manager_status === 'approved') {
                    throw new Exception("Indikator {$item->definition_code_snapshot} sedang dalam review dan tidak dapat diubah.");
                }
                if ($entry->employee_submitted_at !== null
                    && $entry->supervisor_status !== 'revision_required'
                    && $entry->manager_status !== 'revision_required') {
                    throw new Exception("Indikator {$item->definition_code_snapshot} sudah disubmit dan terkunci.");
                }

                $entry->employee_actual_decimal = array_key_exists('actual_decimal', $itemData)
                    && $itemData['actual_decimal'] !== null
                    ? (float) $itemData['actual_decimal']
                    : null;
                $entry->employee_actual_json = $itemData['actual_json'] ?? null;
                $entry->employee_note = isset($itemData['note']) ? (string) $itemData['note'] : null;
                $entry->employee_entered_by = $user->id;
                $entry->employee_submitted_at = $submit ? now() : null;
                $this->resetAssessments($entry);
                $entry->entry_status = $submit ? 'submitted' : 'draft';
                $entry->row_version = ((int) ($entry->row_version ?: 0)) + 1;
                $entry->save();
                $entryMap->put($item->id, $entry);
            }

            if ($submit) {
                $missing = [];
                foreach ($kpi->items->filter(fn (EmployeeKpiItem $item): bool => strtolower((string) $item->source_type_snapshot) === 'employee') as $item) {
                    $entry = $entryMap->get($item->id);
                    $hasValue = $entry && ($entry->employee_actual_decimal !== null
                        || (is_array($entry->employee_actual_json) && $entry->employee_actual_json !== []));
                    if (! $hasValue) {
                        $missing[] = "Item '{$item->name_snapshot}' belum memiliki nilai aktual.";
                    }
                    if ($item->evidence_req_snapshot
                        && $item->evidences->whereIn('scan_status', ['clean', null])->isEmpty()) {
                        $missing[] = "Item '{$item->name_snapshot}' mewajibkan evidence berstatus clean.";
                    }
                }
                if ($missing !== []) {
                    throw new Exception("Submisi gagal:\n- ".implode("\n- ", $missing));
                }

                $wasRevision = $kpi->status === 'revision_required';
                if ($kpi->status !== 'submitted') {
                    $kpi->status = 'submitted';
                    $kpi->submitted_at = now();
                    $kpi->revision_number += $wasRevision ? 1 : 0;
                    $kpi->row_version += 1;
                    $kpi->save();
                }
            }
        });

        return $this->employeeDay($user, $date);
    }

    public function supervisorQueue(User $user, string $date): Collection
    {
        $period = $this->periodForDate($date);
        $this->assertRole($user, 'supervisor');
        $this->operationalSync->syncPeriodOperationalData($period);
        $this->ensureAssignedEntries($user, $period, $date, 'supervisor');

        return KpiDailyEntry::with([
            'item.employeeKpi.employee.position',
            'item.employeeKpi.employee.branch',
            'item.employeeKpi.period',
        ])
            ->whereDate('entry_date', $date)
            ->whereIn('entry_status', ['submitted', 'revision_required'])
            ->whereIn('supervisor_status', ['pending', 'revision_required'])
            ->whereHas('item.employeeKpi', function ($query) use ($user, $period): void {
                $query->where('period_id', $period->id);
                $query->where('supervisor_id_snapshot', $user->employee?->id);
            })
            ->orderBy('id')
            ->get()->filter(fn (KpiDailyEntry $entry): bool => KpiWorkflow::canReviewKpi($user, $entry->item->employeeKpi))->values();
    }

    public function managerQueue(User $user, string $date): Collection
    {
        $period = $this->periodForDate($date);
        $this->assertRole($user, 'manager');
        $this->operationalSync->syncPeriodOperationalData($period);
        $this->ensureAssignedEntries($user, $period, $date, 'manager');

        return KpiDailyEntry::with([
            'item.employeeKpi.employee.position',
            'item.employeeKpi.employee.branch',
            'item.employeeKpi.period',
        ])
            ->whereDate('entry_date', $date)
            ->whereIn('entry_status', ['submitted', 'revision_required'])
            ->whereHas('item.employeeKpi', fn ($query) => $query->where('position_code_snapshot', 'POS-SPV')
                ->orWhere(fn ($legacy) => $legacy->whereNull('position_code_snapshot')
                    ->whereHas('employee.position', fn ($position) => $position->where('code', 'POS-SPV'))))
            ->whereIn('manager_status', ['pending', 'revision_required', 'approved'])
            ->whereHas('item.employeeKpi', function ($query) use ($user, $period): void {
                $query->where('period_id', $period->id);
                $query->where('manager_id_snapshot', $user->employee?->id);
            })
            ->orderBy('id')
            ->get()->filter(fn (KpiDailyEntry $entry): bool => KpiWorkflow::canManageKpi($user, $entry->item->employeeKpi))->values();
    }

    public function assessmentDeadline(string $date, string $role): ?Carbon
    {
        if (! in_array($role, ['supervisor', 'manager'], true)) {
            throw new Exception('Peran penilaian harian tidak valid.');
        }

        $period = $this->periodForDate($date);

        return $role === 'manager' ? $period->approval_deadline : $period->review_deadline;
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
        $kpi->load(['items.dailyEntries', 'employee.position']);
        KpiWorkflow::assertMutableKpi($kpi);
        $changed = false;

        foreach ($kpi->items as $item) {
            $statusColumn = $kpi->isSupervisorKpi() ? 'manager_status' : 'supervisor_status';
            $entries = $item->dailyEntries->reject(fn (KpiDailyEntry $entry): bool => data_get($entry->system_actual_json, 'excluded_from_ratio', false));
            $approved = $entries->where($statusColumn, 'approved');
            $isDailyAggregate = is_array($item->actual_json) && ! empty($item->actual_json['_daily_aggregate']);

            // Sumber operasional menghitung rasio berbobot dan snapshot periode sendiri.
            // Menjumlahkan total bulanan atau merata-ratakan rasio harian mengubah formula.
            if ($item->isSystemSourced() || $item->isAttendanceIndicator()) {
                if ($entries->isNotEmpty()) {
                    $item->status = $approved->count() === $entries->count() && $item->actual_decimal !== null
                        ? 'verified' : 'revision_required';
                    if ($item->status === 'verified' && in_array($item->manager_decision, ['needs_correction', 'data_exception'], true)) {
                        $item->manager_decision = null;
                        $item->manager_decided_at = null;
                        $item->manager_decided_by = null;
                    }
                    if ($item->isDirty()) {
                        $item->save();
                        $changed = true;
                    }
                }

                continue;
            }

            if ($approved->isEmpty()) {
                if ($isDailyAggregate) {
                    $item->actual_decimal = null;
                    $item->actual_json = [
                        '_daily_aggregate' => true,
                        'aggregation' => null,
                        'approved_days' => 0,
                    ];
                    $item->status = 'draft';
                    if ($item->isDirty()) {
                        $item->save();
                        $changed = true;
                    }
                }

                continue;
            }

            $isRubric = $item->formula_key_snapshot === 'rubric' || $item->isManualRated();
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
            $item->status = $approved->count() === $entries->count() && $values->count() === $entries->count()
                ? 'verified' : 'draft';
            if (! $item->isDirty()) {
                continue;
            }
            $item->manager_decision = null;
            $item->manager_decided_at = null;
            $item->manager_decided_by = null;
            $item->row_version += 1;
            $item->save();
            $changed = true;

            if ($userId) {
                KpiActualEntry::create([
                    'employee_kpi_item_id' => $item->id,
                    'input_by' => $userId,
                    'actual_value' => $item->actual_decimal,
                    'actual_json' => $metadata,
                    'notes' => 'Agregasi fakta dan penilaian harian penilai yang ditugaskan.',
                ]);
            }
        }

        if (! $changed) {
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
        if (! in_array($decision, ['approved', 'revision_required'], true)) {
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
            if (! $entry) {
                throw new Exception('Penilaian KPI harian tidak ditemukan.');
            }

            $kpi = $entry->item->employeeKpi;
            $this->assertRole($user, $role);
            $canAssess = $role === 'manager'
                ? KpiWorkflow::canManageKpi($user, $kpi)
                : KpiWorkflow::canReviewKpi($user, $kpi);
            if (! $canAssess || ($role === 'manager' && ! $kpi->isSupervisorKpi())) {
                throw new Exception('Anda tidak berwenang menilai KPI harian ini.');
            }
            KpiWorkflow::assertMutableKpi($kpi);
            $this->assertDailyWindow($kpi->period, $role);

            if (! in_array($entry->entry_status, ['submitted', 'revision_required'], true)) {
                throw new Exception('Entri harian belum siap untuk direview.');
            }
            $managerOwnSupervisorKpi = $role === 'manager'
                && $kpi->isSupervisorKpi();
            if ($role === 'manager' && $entry->supervisor_status !== 'approved' && ! $managerOwnSupervisorKpi) {
                throw new Exception('Penilaian Supervisor harus disetujui terlebih dahulu.');
            }
            if ($decision === 'revision_required' && trim((string) $note) === '') {
                throw new Exception('Alasan revisi wajib diisi.');
            }

            $before = $this->entryAuditPayload($entry);
            $normalizedAnswers = null;
            $score = null;
            $manualRating = null;
            $attendanceStatus = data_get($actualJson, 'attendance_status');
            if ($decision === 'approved') {
                if ($entry->item->isManualRated()) {
                    $ratingCode = data_get($actualJson, 'rating_code');
                    if ($ratingCode !== null) {
                        $manualRating = $entry->item->manualRating((string) $ratingCode);
                        if (! $manualRating) {
                            throw new Exception('Predikat penilaian tidak valid untuk indikator ini.');
                        }
                        $score = (float) $manualRating['score'];
                    } elseif ($answers !== null && $answers !== []) {
                        // Compatibility with snapshots that still use checklist rubric input.
                        [$normalizedAnswers, $score] = $this->normalizeRubricAnswers($entry->item, $answers);
                    } elseif ($actualDecimal !== null) {
                        $score = min(max((float) $actualDecimal, 0.0), 100.0);
                    } else {
                        throw new Exception('Pilih predikat penilaian sebelum menyimpan review.');
                    }
                } elseif ($entry->item->isAttendanceIndicator()) {
                    if ($attendanceStatus !== null && ! in_array($attendanceStatus, Attendance::STATUSES, true)) {
                        throw new Exception('Status kehadiran tidak valid.');
                    }
                    if ($attendanceStatus === null && $actualDecimal === null) {
                        throw new Exception('Status kehadiran wajib dipilih.');
                    }
                    if ($attendanceStatus !== null) {
                        if (in_array($attendanceStatus, [...Attendance::EXCUSED_STATUSES, Attendance::STATUS_ABSENT], true)
                            && trim((string) $note) === '') {
                            throw new Exception('Catatan wajib diisi untuk status kehadiran ini.');
                        }
                        $actualDecimal = in_array($attendanceStatus, Attendance::WORKED_STATUSES, true)
                            ? 100.0
                            : ($attendanceStatus === Attendance::STATUS_ABSENT ? 0.0 : null);
                        $this->recordAttendance($entry, $attendanceStatus, $note, $user->id);
                    }
                } elseif ($entry->item->formula_key_snapshot === 'rubric') {
                    [$normalizedAnswers, $score] = $this->normalizeRubricAnswers($entry->item, $answers ?? []);
                } else {
                    if ($answers !== null && $answers !== []) {
                        throw new Exception('Indikator numerik tidak menerima checklist rubrik.');
                    }
                    $isSystemSource = $entry->item->isSystemSourced();
                    $fallback = $role === 'manager'
                        ? ($entry->supervisor_actual_decimal ?? $entry->employee_actual_decimal)
                        : null;
                    if ($role === 'supervisor' && ! $isSystemSource) {
                        $employeeActual = $entry->employee_actual_decimal;
                        $actualDecimal ??= $employeeActual !== null ? (float) $employeeActual : null;
                        if ($actualDecimal === null) {
                            throw new Exception('Nilai aktual harian wajib dicatat oleh Supervisor.');
                        }
                    } else {
                        $actualDecimal = $actualDecimal ?? ($fallback !== null ? (float) $fallback : null);
                    }
                    if ($role === 'manager' && ! $managerOwnSupervisorKpi
                        && strtolower((string) $entry->item->source_type_snapshot) === 'employee'
                        && $entry->supervisor_actual_decimal === null
                        && (! is_array($entry->supervisor_actual_json) || $entry->supervisor_actual_json === [])
                        && $entry->employee_actual_decimal === null
                        && (! is_array($entry->employee_actual_json) || $entry->employee_actual_json === [])) {
                        throw new Exception('Nilai aktual Supervisor belum dicatat.');
                    }
                    if ($role === 'manager' && ! $managerOwnSupervisorKpi && $actualDecimal !== null) {
                        $baseline = $entry->supervisor_actual_decimal
                            ?? $entry->employee_actual_decimal
                            ?? $entry->item->systemActualDecimal();
                        if ($baseline !== null
                            && abs((float) $actualDecimal - (float) $baseline) > 0.000001) {
                            if (strtolower((string) $entry->item->source_type_snapshot) !== 'employee') {
                                throw new Exception('Nilai sistem, import, dan cross-role tidak dapat dikoreksi manual oleh Manager.');
                            }
                            if (trim((string) $note) === '') {
                                throw new Exception('Koreksi nilai aktual oleh Manager wajib menyertakan alasan.');
                            }
                            if (! is_array($actualJson) || $actualJson === []) {
                                throw new Exception('Koreksi nilai aktual oleh Manager wajib menyertakan evidence.');
                            }
                        }
                    }
                    $systemValue = data_get($entry->system_actual_json, 'cadence') === 'period'
                        ? $entry->item->systemActualDecimal() : $entry->system_actual_decimal;
                    if ($isSystemSource && $actualDecimal !== null && ($systemValue === null
                        || abs($actualDecimal - (float) $systemValue) > 0.000001)) {
                        throw new Exception('Nilai dari sumber resmi tidak boleh diubah melalui penilaian.');
                    }
                    if ($actualDecimal === null && (! $isSystemSource || $systemValue === null)) {
                        throw new Exception($isSystemSource
                            ? 'Nilai sistem belum tersedia. Sinkronkan data operasional terlebih dahulu.'
                            : 'Nilai aktual harian wajib diisi untuk indikator ini.');
                    }
                }

                if ($role === 'manager' && trim((string) $note) === '') {
                    $ratingChanged = $entry->item->isManualRated()
                        && $entry->supervisor_score_percentage !== null
                        && $score !== null
                        && abs((float) $entry->supervisor_score_percentage - (float) $score) > 0.000001;
                    $attendanceChanged = $entry->item->isAttendanceIndicator()
                        && data_get($entry->supervisor_actual_json, 'attendance_status') !== null
                        && $attendanceStatus !== null
                        && data_get($entry->supervisor_actual_json, 'attendance_status') !== $attendanceStatus;
                    $rubricChanged = $entry->item->formula_key_snapshot === 'rubric'
                        && $entry->supervisor_score_percentage !== null
                        && $score !== null
                        && abs((float) $entry->supervisor_score_percentage - (float) $score) > 0.000001;
                    if ($ratingChanged || $attendanceChanged || $rubricChanged) {
                        throw new Exception('Perubahan penilaian Manager wajib menyertakan alasan.');
                    }
                }
            }

            if ($role === 'supervisor') {
                $entry->entry_status = $decision === 'revision_required' ? 'revision_required' : 'submitted';
                $entry->supervisor_actual_decimal = $decision === 'approved'
                    && ! $entry->item->isManualRated()
                    && ! $entry->item->isSystemSourced()
                    ? $actualDecimal
                    : null;
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
                $entry->entry_status = $decision === 'revision_required' ? 'revision_required' : 'submitted';
                $entry->manager_actual_decimal = $decision === 'approved'
                    && ! $entry->item->isManualRated()
                    && $entry->item->formula_key_snapshot !== 'rubric'
                    ? $actualDecimal
                    : null;
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

            if ($entry->item->isAttendanceIndicator()) {
                app(AttendanceKpiSyncService::class)->syncPeriodAttendanceData($kpi->period);
            }
            $this->aggregateKpi($kpi, $user->id);
            if (in_array($kpi->status, ['verified', 'pending_approval'], true)) {
                $kpi->status = 'under_review';
                $kpi->verified_at = null;
                $kpi->row_version += 1;
                $kpi->save();
            }
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
            if (! $criterion) {
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

    private function recordAttendance(KpiDailyEntry $entry, string $status, ?string $note, int $userId): void
    {
        $employee = $entry->item->employeeKpi->employee;
        if (! $employee) {
            throw new Exception('Karyawan untuk indikator kehadiran tidak ditemukan.');
        }

        Attendance::updateOrCreate(
            [
                'employee_id' => $employee->id,
                'attendance_date' => $entry->entry_date->toDateString(),
            ],
            [
                'branch_id' => $employee->branch_id,
                'status' => $status,
                'note' => trim((string) $note) ?: null,
                'recorded_by' => $userId,
                'check_in_time' => null,
                'check_out_time' => null,
            ]
        );

        $entry->system_actual_decimal = in_array($status, Attendance::WORKED_STATUSES, true)
            ? 100.0
            : ($status === Attendance::STATUS_ABSENT ? 0.0 : null);
        $entry->system_actual_json = [
            'attendance_status' => $status,
            'excluded_from_ratio' => in_array($status, Attendance::EXCUSED_STATUSES, true),
        ];
    }

    private function ensureEntries(EmployeeKpi $kpi, string $date): Collection
    {
        return DB::transaction(function () use ($kpi, $date): Collection {
            $kpi = EmployeeKpi::with(['items', 'period', 'employee.position', 'supervisorSnapshot', 'managerSnapshot'])
                ->whereKey($kpi->id)->lockForUpdate()->firstOrFail();
            if (! KpiWorkflow::canSystemSyncKpi($kpi)) {
                return KpiDailyEntry::with('item')->whereIn('employee_kpi_item_id', $kpi->items->pluck('id'))
                    ->whereDate('entry_date', $date)->get();
            }
            foreach ($kpi->items as $item) {
                if ($item->isAttendanceIndicator() && Carbon::parse($date)->isWeekend()) {
                    continue;
                }
                if ($item->cadence() === 'period' && $date !== $kpi->period->end_date->toDateString()) {
                    continue;
                }
                if ($item->cadence() === 'weekly' && ! Carbon::parse($date)->isSunday()
                    && $date !== $kpi->period->end_date->toDateString()) {
                    continue;
                }
                if ($item->isSystemSourced() && ! $item->isAttendanceIndicator()
                    && $date !== $kpi->period->end_date->toDateString()) {
                    continue;
                }
                // DATE queries are normalized explicitly; MySQL DATE and SQLite datetime casts differ.
                $entry = KpiDailyEntry::where('employee_kpi_item_id', $item->id)->whereDate('entry_date', $date)->first();
                $entry ??= KpiDailyEntry::create([
                    'employee_kpi_item_id' => $item->id,
                    'entry_date' => $date,
                    'entry_status' => 'submitted',
                ]);
                if ($item->isSystemSourced() && ! $item->isAttendanceIndicator()
                    && ! $entry->system_actual_json) {
                    $entry->system_actual_decimal = $item->actual_decimal;
                    $entry->system_actual_json = ['cadence' => 'period'];
                    if ($entry->isDirty()) {
                        $entry->save();
                    }
                }

                if ($entry->entry_status === 'draft' && $entry->employee_submitted_at === null) {
                    $entry->entry_status = 'submitted';
                    $entry->row_version = ((int) ($entry->row_version ?: 0)) + 1;
                    $entry->save();
                }
            }

            if ($kpi->status === 'draft') {
                $beforeStatus = $kpi->status;
                KpiWorkflow::assertKpiTransition($kpi, 'submitted');
                $kpi->status = 'submitted';
                $kpi->submitted_at ??= now();
                $kpi->row_version = ((int) ($kpi->row_version ?: 0)) + 1;
                $kpi->save();

                AuditEvent::log(
                    action: 'auto_prepare_kpi_for_supervisor',
                    subjectType: 'EmployeeKpi',
                    subjectId: (string) $kpi->id,
                    before: ['status' => $beforeStatus],
                    after: ['status' => $kpi->status, 'submitted_at' => $kpi->submitted_at],
                    reason: 'Entri KPI harian dibuat otomatis untuk review Supervisor.',
                );
            }

            $entries = KpiDailyEntry::with('item')
                ->whereIn('employee_kpi_item_id', $kpi->items->pluck('id'))
                ->whereDate('entry_date', $date)
                ->orderBy('id')
                ->get();
            if ($entries->contains(fn (KpiDailyEntry $entry): bool => $entry->entry_status === 'submitted')) {
                $this->notifySupervisor($kpi, $date);
            }

            return $entries;
        });
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

        $assignmentColumn = $role === 'manager' ? 'manager_id_snapshot' : 'supervisor_id_snapshot';
        $query->where($assignmentColumn, $user->employee?->id);

        $query->get()->filter(fn (EmployeeKpi $kpi): bool => $role === 'manager'
            ? ($kpi->isSupervisorKpi() && KpiWorkflow::canManageKpi($user, $kpi))
            : KpiWorkflow::canReviewKpi($user, $kpi))
            ->each(fn (EmployeeKpi $kpi) => $this->ensureEntries($kpi, $date));
    }

    private function periodForDate(string $date): KpiPeriod
    {
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            throw new Exception('Tanggal KPI harian harus menggunakan format YYYY-MM-DD.');
        }
        if (! $parsed || $parsed->format('Y-m-d') !== $date || $parsed->isFuture()) {
            throw new Exception('Tanggal KPI harian tidak valid atau belum terjadi.');
        }

        $period = KpiPeriod::where('status', '!=', 'CANCELLED')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->orderByDesc('id')
            ->first();
        if (! $period) {
            throw new Exception('Tidak ada periode KPI untuk tanggal tersebut.');
        }

        return $period;
    }

    private function assertDailyWindow(KpiPeriod $period, string $role): void
    {
        if (! in_array($period->status, ['OPEN', 'SUBMISSION_CLOSED', 'IN_REVIEW', 'WAITING_APPROVAL'], true)) {
            throw new Exception('Periode KPI harian tidak sedang terbuka.');
        }

        $deadline = $role === 'manager' ? $period->approval_deadline : $period->review_deadline;
        if ($deadline?->isPast()) {
            throw new Exception('Batas waktu penilaian KPI harian telah berakhir.');
        }
    }

    private function assertRole(User $user, string $role): void
    {
        $capability = $role === 'manager' ? 'kpi.manager.daily' : 'kpi.supervisor.daily';
        if (! CapabilityMatrix::has($user, $capability)) {
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
            'system_actual_decimal' => $entry->system_actual_decimal,
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
        $assessor = $kpi->isSupervisorKpi() ? $kpi->managerSnapshot : $kpi->supervisorSnapshot;
        $role = $kpi->isSupervisorKpi() ? 'manager' : 'supervisor';
        if ($assessor?->user_id) {
            $body = "Data KPI harian {$date} untuk {$kpi->employee->name} disiapkan otomatis dan siap divalidasi.";
            $alreadyNotified = SystemNotification::query()
                ->where('user_id', $assessor->user_id)
                ->where('type', 'daily_kpi_ready')
                ->where('entity_type', 'EmployeeKpi')
                ->where('entity_id', (string) $kpi->id)
                ->where('body', $body)
                ->exists();
            if ($alreadyNotified) {
                return;
            }

            SystemNotification::send(
                userId: $assessor->user_id,
                title: "KPI Harian Menunggu Review: {$kpi->employee->name}",
                body: $body,
                type: 'daily_kpi_ready',
                entityType: 'EmployeeKpi',
                entityId: (string) $kpi->id,
                actionUrl: "/app/{$role}-daily-assessments?date={$date}"
            );
        }
    }

    private function notifyAssessmentResult(KpiDailyEntry $entry, string $role, string $decision): void
    {
        $kpi = $entry->item->employeeKpi;
        if (in_array($decision, ['approved', 'revision_required'], true)) {
            $employeeUserId = $kpi->employee?->user_id;
            if ($employeeUserId) {
                SystemNotification::send(
                    userId: $employeeUserId,
                    title: $decision === 'approved' ? 'Penilaian KPI Harian Selesai' : 'KPI Harian Perlu Revisi',
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
