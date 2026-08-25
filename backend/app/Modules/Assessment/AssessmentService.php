<?php

namespace App\Modules\Assessment;

use App\Models\AuditEvent;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\KpiActualEntry;
use App\Models\KpiEvidence;
use App\Models\SystemNotification;
use App\Modules\Calculation\KpiCalculationEngine;
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
        ?int $userId = null
    ): EmployeeKpiItem {
        $kpi = $item->employeeKpi;

        // Guard: check status
        if (!in_array($kpi->status, ['draft', 'revision_required'])) {
            throw new Exception("KPI berstatus '{$kpi->status}' dan tidak dapat diedit.");
        }

        if ($kpi->status === 'revision_required' && $item->status !== 'revision_required') {
            throw new Exception("Hanya item yang diminta revisi yang dapat diedit kembali.");
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
        if (!in_array($kpi->status, ['draft', 'revision_required'])) {
            throw new Exception("Tidak dapat mengunggah bukti pada status '{$kpi->status}'.");
        }

        $hash = hash_file('sha256', $file->getRealPath());
        $path = $file->store('evidences/' . date('Y/m'), 'public');

        return KpiEvidence::create([
            'employee_kpi_item_id' => $item->id,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'sha256_hash' => $hash,
            'uploaded_by' => $userId ?? auth()->id() ?? $kpi->employee->user_id,
            'description' => $description,
        ]);
    }

    public function submitKpi(EmployeeKpi $kpi, ?int $userId = null): array
    {
        if (!in_array($kpi->status, ['draft', 'revision_required'])) {
            throw new Exception("KPI berstatus '{$kpi->status}' dan tidak dapat disubmit.");
        }

        $period = $kpi->period;
        if ($period->submission_deadline < now() && $period->status !== 'OPEN') {
            throw new Exception("Batas waktu (deadline) pengisian periode ini telah berakhir.");
        }

        // Validate mandatory items & evidence
        $kpi->loadMissing(['items.evidences']);
        $missingItems = [];

        foreach ($kpi->items as $item) {
            // If item is not filled
            if ($item->actual_decimal === null && $item->actual_json === null && $item->source_type_snapshot === 'employee') {
                $missingItems[] = "Item '{$item->name_snapshot}' belum memiliki nilai aktual.";
            }

            // If evidence is required
            if ($item->evidence_req_snapshot && $item->evidences->isEmpty()) {
                $missingItems[] = "Item '{$item->name_snapshot}' mewajibkan unggahan bukti (evidence).";
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
