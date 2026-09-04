<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Modules\Import\CashierImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ParseCashierImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $batchId) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(CashierImportService $service): void
    {
        $batch = ImportBatch::query()->findOrFail($this->batchId);

        if (!in_array($batch->status, ['parsing', 'uploaded', 'scanning'], true)) {
            return;
        }

        $service->parseAndValidate($batch);
    }

    public function failed(?Throwable $exception): void
    {
        ImportBatch::query()
            ->whereKey($this->batchId)
            ->update([
                'status' => 'failed',
                'issues_json' => [[
                    'severity' => 'error',
                    'code' => 'async_parse_failed',
                    'message' => 'Analisis file gagal setelah beberapa percobaan. Silakan unggah ulang file.',
                ]],
            ]);
    }
}
