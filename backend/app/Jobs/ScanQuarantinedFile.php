<?php

namespace App\Jobs;

use App\Models\EmployeeKpi;
use App\Models\ImportBatch;
use App\Models\KpiEvidence;
use App\Models\ServiceTicket;
use App\Models\SystemNotification;
use App\Modules\Assessment\OperationalKpiSyncService;
use App\Modules\Import\CashierImportService;
use App\Modules\Security\FileScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ScanQuarantinedFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public string $type, public string $id) {}

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(FileScanService $scanner, CashierImportService $imports): void
    {
        if ($this->type === 'service_ticket') {
            $this->scanServiceTicketEvidence($scanner);

            return;
        }
        $record = $this->type === 'import' ? ImportBatch::findOrFail($this->id) : KpiEvidence::findOrFail($this->id);
        if (! in_array($record->scan_status, ['quarantine', 'pending'], true)) {
            return;
        }
        try {
            $result = $scanner->scan(Storage::disk('local')->path($record->file_path));
        } catch (RuntimeException $exception) {
            $record->update(['scan_note' => $exception->getMessage()]);
            throw $exception;
        }
        $record->update([
            'scan_status' => $result,
            'scanned_at' => now(),
            'scan_note' => $result === 'clean' ? 'File aman menurut ClamAV.' : 'File ditolak oleh ClamAV.',
        ]);
        if ($this->type === 'import') {
            if ($result === 'clean') {
                $imports->parseAndValidate($record->fresh());
            } else {
                $record->update(['status' => 'failed']);
            }
            SystemNotification::send(
                $record->uploader_id,
                $result === 'clean' ? 'Import siap ditinjau' : 'Import ditolak',
                $result === 'clean' ? 'File import telah selesai diperiksa.' : 'File import tidak lolos pemeriksaan keamanan.',
                $result === 'clean' ? 'import_ready' : 'import_failed',
                'ImportBatch',
                (string) $record->id,
            );
            $supervisorUserIds = EmployeeKpi::where('period_id', $record->period_id)
                ->where('branch_id_snapshot', $record->branch_id)->where('position_code_snapshot', 'POS-KSR')
                ->whereNotNull('supervisor_id_snapshot')->with('supervisorSnapshot')->get()
                ->pluck('supervisorSnapshot.user_id')->filter()->unique();
            foreach ($supervisorUserIds as $userId) {
                SystemNotification::send((int) $userId,
                    $result === 'clean' ? 'Import siap ditinjau' : 'Import ditolak',
                    $result === 'clean' ? 'File import telah selesai diperiksa.' : 'File import tidak lolos pemeriksaan keamanan.',
                    $result === 'clean' ? 'import_ready' : 'import_failed', 'ImportBatch', (string) $record->id);
            }
        }
    }

    private function scanServiceTicketEvidence(FileScanService $scanner): void
    {
        [$ticketId, $index] = array_pad(explode(':', $this->id, 2), 2, null);
        $ticket = ServiceTicket::findOrFail($ticketId);
        $evidences = $ticket->technical_evidence_json ?? [];
        $evidence = $evidences[(int) $index] ?? null;
        if (! is_array($evidence) || ($evidence['scan_status'] ?? null) !== 'quarantine') {
            return;
        }
        try {
            $result = $scanner->scan(Storage::disk('local')->path($evidence['file_path']));
        } catch (RuntimeException $exception) {
            $evidences[(int) $index]['scan_note'] = $exception->getMessage();
            $ticket->forceFill(['technical_evidence_json' => $evidences])->saveQuietly();
            throw $exception;
        }
        $evidences[(int) $index] = [...$evidence,
            'scan_status' => $result, 'scanned_at' => now()->toIso8601String(),
            'scan_note' => $result === 'clean' ? 'File aman menurut ClamAV.' : 'File ditolak oleh ClamAV.',
        ];
        $ticket->forceFill(['technical_evidence_json' => $evidences])->saveQuietly();
        if ($result === 'clean' && $ticket->period) {
            app(OperationalKpiSyncService::class)->syncPeriodOperationalData($ticket->period);
        }
    }
}
