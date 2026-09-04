<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Models\KpiPeriod;
use App\Modules\Import\CashierImportService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashierApiController extends Controller
{
    public function __construct(
        protected CashierImportService $importService
    ) {}

    public function upload(Request $request): JsonResponse
    {
        // Tolak role yang tidak berwenang sebelum membaca/validasi payload.
        $user = $request->user()->loadMissing('employee');
        if (!$this->isCashierAuthorized($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Kasir yang dapat mengunggah laporan kasir.',
            ], 403);
        }

        $request->validate([
            'file' => 'required|file|max:20480|mimes:xlsx,csv,txt',
            'period_id' => 'required|integer|exists:kpi_periods,id',
        ]);

        $period = KpiPeriod::find($request->integer('period_id'));

        if (!$period) {
            return response()->json(['success' => false, 'message' => 'Periode KPI tidak ditemukan.'], 404);
        }

        if (!$period->isOpen()) {
            return response()->json(['success' => false, 'message' => 'Import hanya dapat dilakukan pada periode yang sedang OPEN.'], 422);
        }

        try {
            $batch = $this->importService->uploadAndStage(
                file: $request->file('file'),
                period: $period,
                uploaderId: $request->user()->id,
                queue: true,
            );

            return response()->json([
                'success' => true,
                'message' => 'File berhasil diunggah dan masuk antrean analisis.',
                'data' => $this->formatBatch($batch),
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function status(Request $request, string $batchId): JsonResponse
    {
        $batch = ImportBatch::query()->find($batchId);

        if (!$batch) {
            return response()->json(['success' => false, 'message' => 'Batch import tidak ditemukan.'], 404);
        }

        $user = $request->user()->loadMissing('employee');
        if (!$this->isCashierAuthorized($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Kasir atau manajemen yang dapat melihat status import.',
            ], 403);
        }

        $isManagerial = $user->hasAnyRole(['owner_manager', 'super_admin', 'supervisor']);
        if (!$isManagerial && (int) $batch->uploader_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Batch import ini bukan milik Anda.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatBatch($batch),
        ]);
    }

    public function confirm(Request $request, string $batchId): JsonResponse
    {
        $request->validate([
            'acknowledge_warnings' => ['sometimes', 'boolean'],
        ]);

        $batch = ImportBatch::where('id', $batchId)->first();

        if (!$batch) {
            return response()->json(['success' => false, 'message' => 'Batch import tidak ditemukan.'], 404);
        }

        $user = $request->user()->loadMissing('employee');
        if (!$this->isCashierAuthorized($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Kasir yang dapat mengonfirmasi import laporan kasir.',
            ], 403);
        }

        // Ownership: kasir hanya bisa konfirmasi batch yang dia unggah sendiri.
        // Manager / supervisor boleh konfirmasi batch siapa pun (fallback operasional).
        $isManagerial = $user->hasAnyRole(['owner_manager', 'super_admin', 'supervisor']);
        if (!$isManagerial && $batch->uploader_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Batch import ini bukan milik Anda.',
            ], 403);
        }

        try {
            $result = $this->importService->confirmAndCommit(
                $batch,
                $request->user()->id,
                $request->boolean('acknowledge_warnings')
            );
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => [
                    'batch_id' => $result['batch']->id,
                    'status' => $result['batch']->status,
                    'confirmed_at' => $result['batch']->confirmed_at?->toIso8601String(),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Akses import laporan kasir: hanya Kasir (POS-KSR) / manager / supervisor.
     */
    private function isCashierAuthorized($user): bool
    {
        if ($user->hasAnyRole(['owner_manager', 'super_admin', 'supervisor'])) {
            return true;
        }

        $employee = $user->employee;
        if (!$employee || !$employee->position) {
            return false;
        }

        return $employee->position->code === 'POS-KSR';
    }

    private function formatBatch(ImportBatch $batch): array
    {
        return [
            'batch_id' => $batch->id,
            'file_name' => $batch->file_name,
            'status' => $batch->status,
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'warning_rows' => $batch->warning_rows,
            'error_rows' => $batch->error_rows,
            'duplicate_rows' => $batch->duplicate_rows,
            'summary' => $batch->summary_json,
            'issues' => $batch->issues_json,
        ];
    }
}
