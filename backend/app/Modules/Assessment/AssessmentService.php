<?php

namespace App\Modules\Assessment;

use App\Models\AuditEvent;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\KpiActualEntry;
use App\Models\KpiEvidence;
use App\Models\SystemNotification;
use App\Models\User;
use App\Modules\Calculation\KpiCalculationEngine;
use App\Support\KpiWorkflow;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AssessmentService
{
    public function __construct(
        protected KpiCalculationEngine $calculationEngine
    ) {}

    public function saveItemDraft(
        EmployeeKpiItem $item,
        ?float $actualDecimal,
        ?array $actualJson = null,
        ?string $notes = null,
        ?int $userId = null,
        ?int $expectedVersion = null
    ): EmployeeKpiItem {
        $kpi = $item->employeeKpi;
        $actor = $userId !== null
            ? User::with('employee')->find($userId)
            : auth()->user();
        if (!$actor || !KpiWorkflow::canEmployeeWriteKpi($actor, $kpi)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Hanya pemilik KPI yang dapat mengisi nilai aktual.');
        }
        if (strtolower((string) $item->source_type_snapshot) !== 'employee'
            || $item->formula_key_snapshot === 'rubric') {
            throw new Exception('Indikator ini diisi oleh sumber resmi lain atau melalui rubrik Supervisor.');
        }
        KpiWorkflow::assertMutableKpi($kpi);
        KpiWorkflow::assertExpectedVersion($item, $expectedVersion);

        if (!in_array($kpi->status, ['draft', 'revision_required'])) {
            throw new Exception("KPI berstatus '{$kpi->status}' dan tidak dapat diedit.");
        }

        if ($kpi->period->status !== 'OPEN' || $kpi->period->submission_deadline->isPast()) {
            throw new Exception('Batas waktu pengisian periode ini telah berakhir.');
        }

        if ($kpi->status === 'revision_required' && $item->status !== 'revision_required') {
            throw new Exception("Hanya item yang diminta revisi yang dapat diedit kembali.");
        }

        if (is_array($actualJson)) {
            unset($actualJson['_system_calculated']);
        }

        DB::transaction(function () use ($item, $kpi, $actualDecimal, $actualJson, $notes, $userId) {
            $item->actual_decimal = $actualDecimal;
            $item->actual_json = $actualJson;
            $item->status = 'draft';
            $item->row_version += 1;
            $item->save();

            // Record entry log
            KpiActualEntry::create([
                'employee_kpi_item_id' => $item->id,
                'input_by' => $userId ?? auth()->id() ?? $kpi->employee->user_id,
                'actual_value' => $actualDecimal,
                'actual_json' => $actualJson,
                'notes' => $notes,
            ]);

            // Recalculate item and KPI progress
            $this->calculationEngine->calculateItem($item);
            $kpi->calculateProgress();
        });

        return $item->fresh();
    }

    public function uploadEvidence(
        EmployeeKpiItem $item,
        UploadedFile $file,
        ?string $description = null,
        ?int $userId = null
    ): KpiEvidence {
        $kpi = $item->employeeKpi;
        $actor = $userId !== null
            ? User::with('employee')->find($userId)
            : auth()->user();
        if (!$actor || !KpiWorkflow::canEmployeeWriteKpi($actor, $kpi)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Hanya pemilik KPI yang dapat mengunggah evidence.');
        }
        if (strtolower((string) $item->source_type_snapshot) !== 'employee') {
            throw new Exception('Evidence hanya dapat ditambahkan pada indikator input karyawan.');
        }
        if (!in_array($kpi->status, ['draft', 'revision_required'])) {
            throw new Exception("Tidak dapat mengunggah bukti pada status '{$kpi->status}'.");
        }

        if ($kpi->period->status !== 'OPEN' || $kpi->period->submission_deadline->isPast()) {
            throw new Exception('Batas waktu pengisian periode ini telah berakhir.');
        }

        KpiWorkflow::assertMutableKpi($kpi);

        $allowedMimes = [
            'image/jpeg', 'image/png', 'application/pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $mime = $file->getMimeType() ?? 'application/octet-stream';
        if (!in_array($mime, $allowedMimes, true) || !$file->isValid()) {
            throw new Exception('Format file evidence tidak didukung atau file rusak.');
        }

        $hash = hash_file('sha256', $file->getRealPath());
        $path = $file->store('evidences/' . date('Y/m'), 'local');

        return KpiEvidence::create([
            'employee_kpi_item_id' => $item->id,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $mime,
            'sha256_hash' => $hash,
            'scan_status' => 'pending',
            'scanned_at' => null,
            'scan_note' => 'Menunggu pemeriksaan keamanan file.',
            'uploaded_by' => $userId ?? auth()->id() ?? $kpi->employee->user_id,
            'description' => $description,
        ]);
    }

    public function submitKpi(EmployeeKpi $kpi, ?int $userId = null): array
    {
        $actor = $userId !== null
            ? User::with('employee')->find($userId)
            : auth()->user();
        if (!$actor || !KpiWorkflow::canEmployeeWriteKpi($actor, $kpi)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Hanya pemilik KPI yang dapat melakukan submit.');
        }
        if (!in_array($kpi->status, ['draft', 'revision_required'])) {
            throw new Exception("KPI berstatus '{$kpi->status}' dan tidak dapat disubmit.");
        }

        $period = $kpi->period;
        if ($period->status !== 'OPEN' || $period->submission_deadline->isPast()) {
            throw new Exception("Batas waktu (deadline) pengisian periode ini telah berakhir.");
        }

        // Validate mandatory items & evidence
        $kpi->loadMissing(['items.evidences']);
        $missingItems = [];

        foreach ($kpi->items as $item) {
            $systemPreview = is_array($item->actual_json)
                && ($item->actual_json['_system_calculated'] ?? false) === true;
            // Rubrik diisi saat review; indikator numerik wajib sudah memiliki
            // nilai dari sistem atau reviewer sebelum KPI dikirim.
            if ($item->actual_decimal === null
                && $item->actual_json === null
                && $item->formula_key_snapshot !== 'rubric') {
                $missingItems[] = "Item '{$item->name_snapshot}' belum memiliki nilai aktual.";
            }
            if (strtolower((string) $item->source_type_snapshot) === 'employee'
                && $systemPreview) {
                $missingItems[] = "Item '{$item->name_snapshot}' masih berupa rekap sistem; nilai aktual karyawan wajib diisi.";
            }

            // Evidence must exist and complete local security validation.
            $usableEvidence = $item->evidences->whereIn('scan_status', ['clean', null]);
            if ($item->evidence_req_snapshot && $usableEvidence->isEmpty()) {
                $missingItems[] = "Item '{$item->name_snapshot}' mewajibkan evidence berstatus clean.";
            }
            if ($item->evidences->contains(fn (KpiEvidence $evidence) => !in_array($evidence->scan_status, ['clean', null], true))) {
                $missingItems[] = "Evidence pada item '{$item->name_snapshot}' masih menunggu pemeriksaan atau ditolak.";
            }
        }

        if (!empty($missingItems)) {
            throw new Exception("Submisi gagal:\n- " . implode("\n- ", $missingItems));
        }

        $beforeStatus = $kpi->status;

        DB::transaction(function () use ($kpi, $beforeStatus, $userId) {
            // Update items status
            foreach ($kpi->items as $item) {
                if ($item->status !== 'verified') {
                    $item->status = 'submitted';
                    $item->save();
                }
            }

            $kpi->status = 'submitted';
            $kpi->submitted_at = now();
            $kpi->row_version += 1;
            $kpi->save();

            // Run calculation
            $this->calculationEngine->calculateKpi($kpi, 'submission', $userId);

            AuditEvent::log(
                action: 'submit_kpi',
                subjectType: 'EmployeeKpi',
                subjectId: (string) $kpi->id,
                before: ['status' => $beforeStatus],
                after: ['status' => 'submitted', 'submitted_at' => $kpi->submitted_at, 'final_score' => $kpi->final_score]
            );

            // Notify Supervisor
            if ($kpi->supervisorSnapshot?->user_id) {
                SystemNotification::send(
                    userId: $kpi->supervisorSnapshot->user_id,
                    title: "KPI Disubmit: {$kpi->employee->name}",
                    body: "Karyawan {$kpi->employee->name} ({$kpi->employee->position->name}) telah mengirimkan KPI untuk periode {$kpi->period->name}. Silakan lakukan review.",
                    type: 'kpi_submitted',
                    entityType: 'EmployeeKpi',
                    entityId: (string) $kpi->id,
                    actionUrl: "/supervisor/review/{$kpi->id}"
                );
            }
        });

        return [
            'success' => true,
            'message' => 'KPI berhasil disubmit dan diteruskan ke Supervisor untuk direview.',
            'kpi' => $kpi->fresh(),
        ];
    }
}
