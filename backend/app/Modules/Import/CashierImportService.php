<?php

namespace App\Modules\Import;

use App\Jobs\ScanQuarantinedFile;
use App\Models\AuditEvent;
use App\Models\CashierTransaction;
use App\Models\EmployeePlacement;
use App\Models\ImportBatch;
use App\Models\ImportMappingVersion;
use App\Models\KpiPeriod;
use App\Models\User;
use App\Modules\Assessment\CashierKpiSyncService;
use App\Modules\Security\FileScanService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Smalot\PdfParser\Parser as PdfParser;

class CashierImportService
{
    private const SOURCE_APPLICATION = 'POS_SYSTEM';

    private const REQUIRED_COLUMNS = [
        'transaction_number',
        'transaction_date',
        'cashier_name',
        'transaction_amount',
        'system_cash_amount',
        'actual_cash_amount',
        'duration_seconds',
        'status',
    ];

    private const VALID_STATUSES = ['SUCCESS', 'REFUND', 'VOID', 'CANCELLED'];

    public function __construct(
        protected CashierKpiSyncService $cashierKpiSync,
    ) {}

    public function uploadAndStage(
        UploadedFile $file,
        KpiPeriod $period,
        ?int $mappingVersionId = null,
        ?int $uploaderId = null,
        bool $queue = false,
    ): ImportBatch {
        if (! $period->isOpen()) {
            throw new Exception('Import hanya dapat dilakukan pada periode yang sedang OPEN.');
        }

        $mapping = ImportMappingVersion::query()->where('is_active', true)
            ->whereHas('template', fn ($query) => $query->where('is_active', true))
            ->when($mappingVersionId, fn ($query) => $query->whereKey($mappingVersionId))
            ->latest('id')->first();
        if ($mappingVersionId && ! $mapping) {
            throw new Exception('Versi mapping import tidak aktif atau tidak ditemukan.');
        }
        $mappingVersionId = $mapping?->id;

        $sourceApplication = $this->sourceApplication($mappingVersionId);
        $hash = hash_file('sha256', $file->getRealPath());
        $existingBatch = ImportBatch::where('file_hash_sha256', $hash)
            ->where('source_application', $sourceApplication)
            ->where('status', '!=', 'superseded')
            ->first();

        if ($existingBatch) {
            throw new Exception("File ini identik (hash SHA-256 sama) dengan batch import #{$existingBatch->id} yang telah berhasil diproses sebelumnya.");
        }

        $path = $file->store('quarantine/imports/'.date('Y/m'), 'local');
        $actorId = $uploaderId ?? auth()->id();
        $actor = $actorId ? User::with('employee.placements.position')->find($actorId) : null;
        $branchId = $actor?->employee?->placements()->effectiveOn(now())->value('branch_id')
            ?? $period->branches()->orderBy('branches.id')->value('branches.id');
        if (! $branchId || ! $period->branches()->whereKey($branchId)->exists()) {
            throw new Exception('Cabang uploader tidak termasuk dalam periode import.');
        }

        $batch = ImportBatch::create([
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_hash_sha256' => $hash,
            'source_application' => $sourceApplication,
            'mapping_version_id' => $mappingVersionId,
            'period_id' => $period->id,
            'branch_id' => $branchId,
            'currency' => 'IDR',
            'uploader_id' => $actorId,
            'status' => 'scanning',
            'scan_status' => 'quarantine',
            'total_rows' => 0,
            'valid_rows' => 0,
            'warning_rows' => 0,
            'error_rows' => 0,
            'duplicate_rows' => 0,
        ]);

        if ($queue) {
            ScanQuarantinedFile::dispatch('import', (string) $batch->id);

            return $batch->fresh();
        }

        (new ScanQuarantinedFile('import', (string) $batch->id))
            ->handle(app(FileScanService::class), $this);

        return $batch->fresh();
    }

    public function parseAndValidate(ImportBatch $batch, ?string $filePath = null): void
    {
        if ($batch->scan_status !== 'clean') {
            throw new Exception('File import belum dinyatakan clean oleh ClamAV.');
        }
        $realPath = $filePath
            ?? (Storage::disk('local')->exists($batch->file_path)
                ? Storage::disk('local')->path($batch->file_path)
                : storage_path('app/'.$batch->file_path));

        if (! file_exists($realPath)) {
            $batch->update([
                'status' => 'failed',
                'issues_json' => [['severity' => 'error', 'code' => 'file_missing', 'message' => 'File tidak ditemukan di storage.']],
            ]);

            return;
        }

        try {
            $rows = $this->readRows($realPath, $batch);
            if ($rows === null) {
                return;
            }

            if (count($rows) < 2) {
                $this->failBatch($batch, 'File kosong atau tidak memiliki baris data setelah header.');

                return;
            }

            $headerColMap = $this->headerMap(array_shift($rows), $batch->mappingVersion?->mappings_json ?? []);
            $missingColumns = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($headerColMap)));
            if ($missingColumns) {
                $this->failBatch($batch, 'Header wajib tidak lengkap: '.implode(', ', $missingColumns).'.');

                return;
            }

            $cashiers = EmployeePlacement::with(['employee', 'position'])
                ->where('branch_id', $batch->branch_id)
                ->whereHas('position', fn ($query) => $query->where('code', 'POS-KSR'))
                ->get();
            $period = $batch->period()->firstOrFail();
            $sourceApplication = $batch->source_application ?: self::SOURCE_APPLICATION;
            $parsedData = [];
            $issues = [];
            $seenKeys = [];
            $totalRows = $validRows = $warningRows = $errorRows = $duplicateRows = 0;

            foreach ($rows as $rowIndex => $row) {
                if ($this->isEmptyRow($row)) {
                    continue;
                }

                $totalRows++;
                $normalized = $this->normalizeRow($row, $headerColMap, $period, $cashiers, $sourceApplication, (int) $batch->branch_id);
                $businessKey = $normalized['business_key'];
                $rowIssues = $normalized['issues'];

                if (isset($seenKeys[$businessKey])) {
                    $rowIssues[] = [
                        'severity' => 'error',
                        'code' => 'duplicate_in_file',
                        'message' => "Nomor transaksi '{$normalized['transaction_number']}' muncul lebih dari sekali dalam file.",
                    ];
                    $duplicateRows++;
                } else {
                    $seenKeys[$businessKey] = true;
                }

                if (CashierTransaction::where('period_id', $batch->period_id)
                    ->where('source_application', $sourceApplication)
                    ->where('business_key', $businessKey)
                    ->exists()) {
                    $rowIssues[] = [
                        'severity' => 'error',
                        'code' => 'duplicate_in_database',
                        'message' => "Transaksi '{$normalized['transaction_number']}' sudah ada pada periode ini.",
                    ];
                    $duplicateRows++;
                }

                $severity = collect($rowIssues)->contains(fn (array $issue) => $issue['severity'] === 'error')
                    ? 'error'
                    : (count($rowIssues) ? 'warning' : 'valid');

                if ($severity === 'error') {
                    $errorRows++;
                } elseif ($severity === 'warning') {
                    $warningRows++;
                } else {
                    $validRows++;
                }

                foreach ($rowIssues as $issue) {
                    $issues[] = ['row' => $rowIndex, ...$issue];
                }

                $parsedData[] = [
                    ...$normalized['data'],
                    'row_number' => $rowIndex,
                    'row_status' => $severity,
                ];
            }

            if ($totalRows === 0) {
                $this->failBatch($batch, 'File tidak memiliki baris transaksi yang dapat diproses.');

                return;
            }

            $batch->update([
                'status' => collect($issues)->contains(fn (array $issue): bool => str_starts_with($issue['code'], 'cashier_'))
                    ? 'needs_mapping' : 'ready_for_preview',
                'total_rows' => $totalRows,
                'valid_rows' => $validRows,
                'warning_rows' => $warningRows,
                'error_rows' => $errorRows,
                'duplicate_rows' => $duplicateRows,
                'summary_json' => [
                    'header_mapping' => $headerColMap,
                    'source_application' => $sourceApplication,
                    'normalized_rows' => $parsedData,
                    'sample_rows' => array_slice($parsedData, 0, 10),
                    'total_amount' => array_sum(array_map(fn (array $row) => (float) ($row['transaction_amount'] ?? 0), $parsedData)),
                    'total_difference' => array_sum(array_map(fn (array $row) => (float) ($row['cash_difference'] ?? 0), $parsedData)),
                ],
                'issues_json' => array_slice($issues, 0, 200),
            ]);
        } catch (Exception) {
            $this->failBatch($batch, 'File tidak dapat dianalisis. Periksa format dan isi file, lalu coba lagi.');
        }
    }

    public function confirmAndCommit(
        ImportBatch $batch,
        ?int $confirmerId = null,
        bool $acknowledgeWarnings = false
    ): array {
        return DB::transaction(function () use ($batch, $confirmerId, $acknowledgeWarnings): array {
            $batch = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (in_array($batch->status, ['confirmed', 'committed'], true)) {
                return [
                    'success' => true,
                    'message' => 'Batch import ini sudah pernah dikonfirmasi.',
                    'batch' => $batch,
                ];
            }

            if ($batch->status !== 'ready_for_preview') {
                throw new Exception("Batch import berstatus '{$batch->status}' dan tidak siap dikonfirmasi.");
            }

            if (! $batch->period()->where('status', 'OPEN')->exists()) {
                throw new Exception('Periode import sudah tidak OPEN. Batch tidak dapat dikonfirmasi.');
            }

            if ($batch->error_rows > 0) {
                throw new Exception('Batch memiliki baris error yang harus diperbaiki sebelum dikonfirmasi.');
            }

            if ($batch->warning_rows > 0 && ! $acknowledgeWarnings) {
                throw new Exception('Batch memiliki peringatan. Tinjau lalu kirim acknowledge_warnings untuk melanjutkan.');
            }

            $rows = $batch->summary_json['normalized_rows'] ?? [];
            if (! $rows) {
                throw new Exception('Data hasil validasi tidak tersedia. Unggah ulang file untuk membuat preview baru.');
            }

            $batch->update([
                'status' => 'committing',
                'warnings_acknowledged_at' => $batch->warning_rows > 0 ? now() : null,
            ]);

            $committedCount = 0;
            $cashierTotals = [];
            foreach ($rows as $row) {
                if (($row['row_status'] ?? 'error') !== 'valid' && ($row['row_status'] ?? 'error') !== 'warning') {
                    continue;
                }

                $transaction = CashierTransaction::firstOrCreate(
                    [
                        'period_id' => $batch->period_id,
                        'source_application' => $batch->source_application ?: self::SOURCE_APPLICATION,
                        'business_key' => $row['business_key'],
                    ],
                    [
                        'import_batch_id' => $batch->id,
                        'cashier_employee_id' => $row['cashier_employee_id'],
                        'cashier_name_raw' => $row['cashier_name_raw'],
                        'transaction_number' => $row['transaction_number'],
                        'transaction_date' => $row['transaction_date'],
                        'transaction_amount' => $row['transaction_amount'],
                        'system_cash_amount' => $row['system_cash_amount'],
                        'actual_cash_amount' => $row['actual_cash_amount'],
                        'cash_difference' => $row['cash_difference'],
                        'duration_seconds' => $row['duration_seconds'],
                        'status' => $row['status'],
                        'is_duplicate' => false,
                    ]
                );

                if (! $transaction->wasRecentlyCreated) {
                    continue;
                }

                $committedCount++;
                $cid = $row['cashier_employee_id'];
                if ($cid) {
                    $cashierTotals[$cid] ??= [
                        'eligible_tx' => 0,
                        'valid_tx' => 0,
                        'total_diff' => 0.0,
                        'duration_within_sla' => 0,
                        'duration_count' => 0,
                        'reported_on_time' => 0,
                    ];
                    $cashierTotals[$cid]['eligible_tx']++;
                    if ($row['status'] === 'SUCCESS') {
                        $cashierTotals[$cid]['valid_tx']++;
                    }
                    $cashierTotals[$cid]['total_diff'] += (float) $row['cash_difference'];
                    if ($row['duration_seconds'] !== null) {
                        $cashierTotals[$cid]['duration_count']++;
                        if ((int) $row['duration_seconds'] <= 180) {
                            $cashierTotals[$cid]['duration_within_sla']++;
                        }
                    }
                }
            }

            $batch->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'confirmed_by' => $confirmerId ?? auth()->id(),
            ]);

            $batch->refresh();
            $this->cashierKpiSync->syncPeriod($batch->period);

            AuditEvent::log(
                action: 'confirm_cashier_import',
                subjectType: 'ImportBatch',
                subjectId: (string) $batch->id,
                after: [
                    'committed_rows' => $committedCount,
                    'cashiers_updated' => count($cashierTotals),
                    'warnings_acknowledged' => $batch->warnings_acknowledged_at !== null,
                ],
                actorId: $confirmerId
            );

            return [
                'success' => true,
                'message' => "Import berhasil dikonfirmasi. {$committedCount} transaksi berhasil dicatat.",
                'batch' => $batch->fresh(),
            ];
        });
    }

    private function normalizeRow(
        array $row,
        array $headerColMap,
        KpiPeriod $period,
        $cashiers,
        string $sourceApplication,
        int $branchId,
    ): array {
        $value = fn (string $key): string => trim((string) ($row[$headerColMap[$key]] ?? ''));
        $txNum = $value('transaction_number');
        $cashierName = $value('cashier_name');
        $date = $this->parseDate($row[$headerColMap['transaction_date']] ?? null);
        $amount = $this->parseMoney($row[$headerColMap['transaction_amount']] ?? null);
        $systemCash = $this->parseMoney($row[$headerColMap['system_cash_amount']] ?? null);
        $actualCash = $this->parseMoney($row[$headerColMap['actual_cash_amount']] ?? null);
        $duration = $this->parseInteger($row[$headerColMap['duration_seconds']] ?? null);
        $status = strtoupper($value('status'));
        $issues = [];

        if ($txNum === '') {
            $issues[] = ['severity' => 'error', 'code' => 'missing_transaction_number', 'message' => 'Nomor transaksi wajib diisi.'];
        }
        if (! $date) {
            $issues[] = ['severity' => 'error', 'code' => 'invalid_transaction_date', 'message' => 'Tanggal transaksi tidak valid.'];
        }
        if ($date && ($date->toDateString() < $period->start_date->toDateString() || $date->toDateString() > $period->end_date->toDateString())) {
            $issues[] = ['severity' => 'error', 'code' => 'transaction_date_outside_period', 'message' => 'Tanggal transaksi berada di luar rentang periode KPI.'];
        }
        if ($cashierName === '') {
            $issues[] = ['severity' => 'error', 'code' => 'missing_cashier', 'message' => 'Kasir wajib diisi dan harus dapat dipetakan.'];
        }
        if ($amount === null || $amount < 0) {
            $issues[] = ['severity' => 'error', 'code' => 'invalid_amount', 'message' => 'Nominal transaksi tidak valid.'];
        }
        if ($systemCash === null || $systemCash < 0) {
            $issues[] = ['severity' => 'error', 'code' => 'invalid_system_cash', 'message' => 'Kas sistem tidak valid.'];
        }
        if ($actualCash === null || $actualCash < 0) {
            $issues[] = ['severity' => 'error', 'code' => 'invalid_actual_cash', 'message' => 'Kas aktual tidak valid.'];
        }
        if ($duration === null || $duration < 0) {
            $issues[] = ['severity' => 'error', 'code' => 'invalid_duration', 'message' => 'Durasi transaksi tidak valid.'];
        }
        if (! in_array($status, self::VALID_STATUSES, true)) {
            $issues[] = ['severity' => 'error', 'code' => 'invalid_status', 'message' => 'Status transaksi tidak dikenali.'];
        }

        $matches = $cashiers->filter(function (EmployeePlacement $placement) use ($cashierName, $date): bool {
            $employee = $placement->employee;

            return $date && $placement->effective_from->lte($date)
                && ($placement->effective_until === null || $placement->effective_until->gte($date))
                && (strcasecmp(trim($employee->name), $cashierName) === 0
                    || strcasecmp(trim((string) $employee->employee_number), $cashierName) === 0);
        })->values();
        $matchedCashier = $matches->count() === 1 ? $matches->first()->employee : null;
        if ($matches->count() > 1) {
            $issues[] = ['severity' => 'error', 'code' => 'cashier_ambiguous', 'message' => "Kasir '{$cashierName}' ambigu pada cabang dan tanggal transaksi."];
        } elseif (! $matchedCashier) {
            $issues[] = ['severity' => 'error', 'code' => 'cashier_unresolved', 'message' => "Kasir '{$cashierName}' tidak ditemukan. Perbaiki mapping sebelum konfirmasi."];
        }

        $businessKey = $sourceApplication.'|'.$branchId.'|'.mb_strtolower($txNum);

        return [
            'data' => [
                'transaction_number' => $txNum,
                'transaction_date' => $date?->toDateTimeString(),
                'cashier_employee_id' => $matchedCashier?->id,
                'cashier_name_raw' => $cashierName,
                'transaction_amount' => $amount,
                'system_cash_amount' => $systemCash,
                'actual_cash_amount' => $actualCash,
                'cash_difference' => $systemCash !== null && $actualCash !== null ? round(abs($actualCash - $systemCash), 2) : null,
                'duration_seconds' => $duration,
                'status' => $status,
                'business_key' => $businessKey,
            ],
            'issues' => $issues,
            'business_key' => $businessKey,
            'transaction_number' => $txNum,
        ];
    }

    private function headerMap(array $headers, array $mapping = []): array
    {
        $map = [];
        $configured = [];
        foreach ($mapping as $field => $header) {
            if (in_array($field, self::REQUIRED_COLUMNS, true)) {
                $configured[preg_replace('/[^a-z0-9]/', '', strtolower((string) $header))] = $field;
            }
        }
        foreach ($headers as $column => $header) {
            $name = preg_replace('/[^a-z0-9]/', '', strtolower((string) $header));
            $canonical = array_search($name, array_map(static fn ($field) => str_replace('_', '', $field), self::REQUIRED_COLUMNS), true);
            $field = $configured[$name] ?? ($canonical !== false ? self::REQUIRED_COLUMNS[$canonical] : null);
            if ($configured) {
                $field = $configured[$name] ?? null;
            } else {
                $field ??= match (true) {
                    in_array($name, ['noinvoice', 'transaksi', 'notransaksi', 'transactionno', 'transactionnumber', 'invoice', 'nomor']) => 'transaction_number',
                    in_array($name, ['tanggal', 'date', 'transactiondate', 'waktu']) => 'transaction_date',
                    in_array($name, ['kasir', 'namakasir', 'cashier', 'cashiername', 'operator']) => 'cashier_name',
                    in_array($name, ['total', 'grandtotal', 'amount', 'nominal', 'totaltransaksi']) => 'transaction_amount',
                    in_array($name, ['kassistim', 'systemcash', 'kassistem', 'totalsistem']) => 'system_cash_amount',
                    in_array($name, ['kasaktual', 'actualcash', 'kasfisik', 'totalaktual']) => 'actual_cash_amount',
                    in_array($name, ['durasi', 'duration', 'durasidetik', 'seconds']) => 'duration_seconds',
                    in_array($name, ['status', 'tipe', 'state']) => 'status',
                    default => null,
                };
            }
            if ($field && isset($map[$field])) {
                throw new Exception('Header laporan memetakan lebih dari satu kolom ke '.$field.'.');
            }
            if ($field) {
                $map[$field] = $column;
            }
        }

        return $map;
    }

    private function readRows(string $path, ImportBatch $batch): ?array
    {
        if (strtolower(pathinfo($batch->file_name, PATHINFO_EXTENSION)) !== 'pdf') {
            return IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, true);
        }

        $text = trim((new PdfParser)->parseFile($path)->getText());
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));
        if (count($lines) < 2) {
            $batch->update([
                'status' => 'needs_review',
                'issues_json' => [[
                    'severity' => 'error', 'code' => 'ocr_required',
                    'message' => 'PDF tidak memiliki tabel teks yang dapat dibaca. OCR/manual review diperlukan.',
                ]],
            ]);

            return null;
        }

        $header = $lines[0];
        $delimiter = collect(["\t", ';', ',', '|'])->sortByDesc(fn (string $candidate): int => substr_count($header, $candidate))->first();
        $parts = $delimiter && substr_count($header, $delimiter) > 0
            ? fn (string $line): array => str_getcsv($line, $delimiter)
            : fn (string $line): array => preg_split('/\s{2,}/', trim($line)) ?: [];

        return array_map(function (string $line) use ($parts): array {
            $row = [];
            foreach ($parts($line) as $index => $value) {
                $row[Coordinate::stringFromColumnIndex($index + 1)] = $value;
            }

            return $row;
        }, $lines);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            return is_numeric($value) ? Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value)) : Carbon::parse((string) $value);
        } catch (Exception) {
            return null;
        }
    }

    private function parseMoney(mixed $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $value = preg_replace('/[^0-9,.-]/', '', $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $decimal = max($lastComma, $lastDot);
            $value = str_replace([',', '.'], '', substr($value, 0, $decimal)).'.'.substr($value, $decimal + 1);
        } elseif ($lastComma !== false && strlen($value) - $lastComma - 1 === 2) {
            $value = str_replace('.', '', substr($value, 0, $lastComma)).'.'.substr($value, $lastComma + 1);
        } else {
            $value = str_replace(',', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function parseInteger(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value !== '' && preg_match('/^\d+$/', $value) ? (int) $value : null;
    }

    private function isEmptyRow(array $row): bool
    {
        return ! array_filter($row, fn ($value) => trim((string) $value) !== '');
    }

    private function sourceApplication(?int $mappingVersionId): string
    {
        if (! $mappingVersionId) {
            return self::SOURCE_APPLICATION;
        }

        return (string) (ImportMappingVersion::with('template')->find($mappingVersionId)?->template?->source_application ?: self::SOURCE_APPLICATION);
    }

    private function failBatch(ImportBatch $batch, string $message): void
    {
        $batch->update([
            'status' => 'failed',
            'issues_json' => [['severity' => 'error', 'code' => 'invalid_file', 'message' => $message]],
        ]);
    }
}
