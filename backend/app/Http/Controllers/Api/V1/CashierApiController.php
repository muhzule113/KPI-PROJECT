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
        $request->validate([
            'file' => 'required|file|max:20480|mimes:xlsx,csv,txt',
            'period_id' => 'nullable|integer',
        ]);

        $period = $request->period_id 
            ? KpiPeriod::find($request->period_id) 
            : KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();

        if (!$period) {
            return response()->json(['success' => false, 'message' => 'Periode KPI tidak ditemukan.'], 404);
        }

        try {
            $batch = $this->importService->uploadAndStage(
                file: $request->file('file'),
                period: $period,
                uploaderId: $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'File berhasil diunggah dan dianalisis.',
                'data' => [
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
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function confirm(Request $request, string $batchId): JsonResponse
    {
        $batch = ImportBatch::where('id', $batchId)->first();
        if (!$batch) {
            return response()->json(['success' => false, 'message' => 'Batch import tidak ditemukan.'], 404);
        }

        try {
            $result = $this->importService->confirmAndCommit($batch, $request->user()->id);
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
}
