<?php

namespace App\Modules\Import;

use App\Models\AuditEvent;
use App\Models\CashierTransaction;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Models\ImportBatch;
use App\Models\ImportMappingVersion;
use App\Models\KpiPeriod;
use App\Modules\Calculation\KpiCalculationEngine;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CashierImportService
{
    public function __construct(
        protected KpiCalculationEngine $calculationEngine
    ) {}

    public function uploadAndStage(
        UploadedFile $file,
        KpiPeriod $period,
        ?int $mappingVersionId = null,
        ?int $uploaderId = null
    ): ImportBatch {
        $hash = hash_file('sha256', $file->getRealPath());

        // Deduplication 1: Check duplicate file hash
        $existingBatch = ImportBatch::where('file_hash_sha256', $hash)
            ->whereIn('status', ['confirmed', 'committed'])
            ->first();

        if ($existingBatch) {
            throw new Exception("File ini identik (hash SHA-256 sama) dengan batch import #{$existingBatch->id} yang telah berhasil diproses sebelumnya.");
        }

        $path = $file->store('imports/' . date('Y/m'), 'local');

        $batch = ImportBatch::create([
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_hash_sha256' => $hash,
            'mapping_version_id' => $mappingVersionId,
            'period_id' => $period->id,
            'uploader_id' => $uploaderId ?? auth()->id(),
            'status' => 'parsing',
            'total_rows' => 0,
            'valid_rows' => 0,
            'warning_rows' => 0,
            'error_rows' => 0,
            'duplicate_rows' => 0,
        ]);

        $this->parseAndValidate($batch, $file->getRealPath());

        return $batch->fresh();
    }

    public function parseAndValidate(ImportBatch $batch, ?string $filePath = null): void
    {
        $realPath = $filePath ?? (Storage::disk('local')->exists($batch->file_path) ? Storage::disk('local')->path($batch->file_path) : storage_path('app/' . $batch->file_path));
        if (!file_exists($realPath)) {
            $batch->update(['status' => 'failed', 'issues_json' => ['error' => 'File tidak ditemukan di storage.']]);
            return;
        }

        try {
            $spreadsheet = IOFactory::load($realPath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);

            if (count($rows) < 2) {
                $batch->update([
                    'status' => 'failed',
                    'issues_json' => ['error' => 'File kosong atau tidak memiliki baris data setelah header.'],
                ]);
                return;
            }

            // Headers are at row 1
            $headers = array_shift($rows);
            $normalizedHeaders = array_map(function ($h) {
                return strtolower(trim(str_replace([' ', '_', '-'], '', (string)$h)));
            }, $headers);

            $headerColMap = [];
            foreach ($normalizedHeaders as $col => $headerName) {
                if (in_array($headerName, ['noinvoice', 'transaksi', 'notransaksi', 'transactionno', 'transactionnumber', 'invoice', 'nomor'])) {
                    $headerColMap['transaction_number'] = $col;
                } elseif (in_array($headerName, ['tanggal', 'date', 'transactiondate', 'waktu'])) {
                    $headerColMap['transaction_date'] = $col;
                } elseif (in_array($headerName, ['kasir', 'namakasir', 'cashier', 'cashiername', 'operator'])) {
                    $headerColMap['cashier_name'] = $col;
                } elseif (in_array($headerName, ['total', 'grandtotal', 'amount', 'nominal', 'totaltransaksi'])) {
                    $headerColMap['transaction_amount'] = $col;
                } elseif (in_array($headerName, ['kassistim', 'systemcash', 'kassistem', 'totalsistem'])) {
                    $headerColMap['system_cash_amount'] = $col;
                } elseif (in_array($headerName, ['kasaktual', 'actualcash', 'kasfisik', 'totalaktual'])) {
                    $headerColMap['actual_cash_amount'] = $col;
                } elseif (in_array($headerName, ['durasi', 'duration', 'durasi(detik)', 'durasidetik', 'seconds'])) {
                    $headerColMap['duration_seconds'] = $col;
                } elseif (in_array($headerName, ['status', 'tipe', 'state'])) {
                    $headerColMap['status'] = $col;
                }
            }

            $totalRows = 0;
            $validRows = 0;
            $warningRows = 0;
            $errorRows = 0;
            $duplicateRows = 0;
            $issues = [];
            $parsedData = [];
            $seenTxNumbers = [];

            // Active cashiers lookup
            $cashiers = Employee::where('status', 'active')->get();

            foreach ($rows as $rowIndex => $row) {
                // Skip completely empty rows
                $rowValues = array_filter($row, fn($v) => !empty(trim((string)$v)));
                if (empty($rowValues)) continue;

                $totalRows++;

                $txNum = isset($headerColMap['transaction_number']) ? trim((string)($row[$headerColMap['transaction_number']] ?? '')) : null;
                if (empty($txNum)) {
                    $txNum = 'TX-' . date('Ymd') . '-' . str_pad((string)$totalRows, 4, '0', STR_PAD_LEFT);
                }

                $txDateStr = isset($headerColMap['transaction_date']) ? trim((string)($row[$headerColMap['transaction_date']] ?? '')) : null;
                $txDate = !empty($txDateStr) ? date('Y-m-d H:i:s', strtotime($txDateStr) ?: time()) : now()->toDateTimeString();

                $cashierName = isset($headerColMap['cashier_name']) ? trim((string)($row[$headerColMap['cashier_name']] ?? '')) : '';
                $amount = isset($headerColMap['transaction_amount']) ? (float) preg_replace('/[^0-9.]/', '', (string)$row[$headerColMap['transaction_amount']]) : 0.0;
                $sysCash = isset($headerColMap['system_cash_amount']) ? (float) preg_replace('/[^0-9.]/', '', (string)$row[$headerColMap['system_cash_amount']]) : $amount;
                $actCash = isset($headerColMap['actual_cash_amount']) ? (float) preg_replace('/[^0-9.]/', '', (string)$row[$headerColMap['actual_cash_amount']]) : $sysCash;
                $diff = abs($actCash - $sysCash);
                $duration = isset($headerColMap['duration_seconds']) ? (int) preg_replace('/[^0-9]/', '', (string)$row[$headerColMap['duration_seconds']]) : 60;
                $status = isset($headerColMap['status']) ? strtoupper(trim((string)($row[$headerColMap['status']] ?? 'SUCCESS'))) : 'SUCCESS';

                // Match cashier to employee
                $matchedCashier = null;
                if (!empty($cashierName)) {
                    $matchedCashier = $cashiers->first(function ($c) use ($cashierName) {
                        return stripos($c->name, $cashierName) !== false || stripos($c->employee_number, $cashierName) !== false;
                    });
                }
                if (!$matchedCashier) {
                    $matchedCashier = $cashiers->firstWhere('position.name', 'Kasir') ?? $cashiers->first();
                }

                // Deduplication 2: Check duplicate transaction within file or database
                $isDuplicate = false;
                if (isset($seenTxNumbers[$txNum])) {
                    $isDuplicate = true;
                    $duplicateRows++;
                } else {
                    $seenTxNumbers[$txNum] = true;
                    $dbDuplicate = CashierTransaction::where('transaction_number', $txNum)
                        ->where('period_id', $batch->period_id)
                        ->exists();
                    if ($dbDuplicate) {
                        $isDuplicate = true;
                        $duplicateRows++;
                    }
                }

                if ($isDuplicate) {
                    $issues[] = ['row' => $rowIndex, 'type' => 'duplicate', 'message' => "Nomor transaksi '{$txNum}' terdeteksi duplikat."];
                } elseif (empty($matchedCashier)) {
                    $warningRows++;
                    $issues[] = ['row' => $rowIndex, 'type' => 'warning', 'message' => "Kasir '{$cashierName}' tidak ditemukan di data karyawan."];
                } else {
                    $validRows++;
                }

                $parsedData[] = [
                    'transaction_number' => $txNum,
                    'transaction_date' => $txDate,
                    'cashier_employee_id' => $matchedCashier?->id,
                    'cashier_name_raw' => $cashierName,
                    'transaction_amount' => $amount,
                    'system_cash_amount' => $sysCash,
                    'actual_cash_amount' => $actCash,
                    'cash_difference' => $diff,
                    'duration_seconds' => $duration,
                    'status' => $status,
                    'is_duplicate' => $isDuplicate,
                ];
            }

            $batch->update([
                'status' => 'ready_for_preview',
                'total_rows' => $totalRows,
                'valid_rows' => $validRows,
                'warning_rows' => $warningRows,
                'error_rows' => $errorRows,
                'duplicate_rows' => $duplicateRows,
                'summary_json' => [
                    'header_mapping' => $headerColMap,
                    'sample_rows' => array_slice($parsedData, 0, 10),
                    'total_amount' => array_sum(array_column($parsedData, 'transaction_amount')),
                    'total_difference' => array_sum(array_column($parsedData, 'cash_difference')),
                ],
                'issues_json' => array_slice($issues, 0, 50),
            ]);
        } catch (Exception $e) {
            $batch->update([
                'status' => 'failed',
                'issues_json' => ['error' => 'Parsing error: ' . $e->getMessage()],
            ]);
        }
    }

    public function confirmAndCommit(ImportBatch $batch, ?int $confirmerId = null): array
    {
        if ($batch->status !== 'ready_for_preview') {
            throw new Exception("Batch import berstatus '{$batch->status}' dan tidak siap dikonfirmasi.");
        }

        $sampleData = $batch->summary_json['sample_rows'] ?? [];
        if (empty($sampleData)) {
            // Re-read and parse completely for commit
        }

        return DB::transaction(function () use ($batch, $confirmerId) {
            $realPath = Storage::disk('local')->exists($batch->file_path) ? Storage::disk('local')->path($batch->file_path) : storage_path('app/' . $batch->file_path);
            $spreadsheet = IOFactory::load($realPath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
            $headers = array_shift($rows);

            $normalizedHeaders = array_map(fn($h) => strtolower(trim(str_replace([' ', '_', '-'], '', (string)$h))), $headers);
            $headerColMap = $batch->summary_json['header_mapping'] ?? [];

            $cashiers = Employee::where('status', 'active')->get();
            $committedCount = 0;
            $cashierTotals = [];

            $i = 0;
            foreach ($rows as $row) {
                $rowValues = array_filter($row, fn($v) => !empty(trim((string)$v)));
                if (empty($rowValues)) continue;
                $i++;

                $txNum = isset($headerColMap['transaction_number']) ? trim((string)($row[$headerColMap['transaction_number']] ?? '')) : 'TX-' . $i;
                $txDateStr = isset($headerColMap['transaction_date']) ? trim((string)($row[$headerColMap['transaction_date']] ?? '')) : null;
                $txDate = !empty($txDateStr) ? date('Y-m-d H:i:s', strtotime($txDateStr) ?: time()) : now()->toDateTimeString();
                $cashierName = isset($headerColMap['cashier_name']) ? trim((string)($row[$headerColMap['cashier_name']] ?? '')) : '';
                $amount = isset($headerColMap['transaction_amount']) ? (float) preg_replace('/[^0-9.]/', '', (string)$row[$headerColMap['transaction_amount']]) : 0.0;
                $sysCash = isset($headerColMap['system_cash_amount']) ? (float) preg_replace('/[^0-9.]/', '', (string)$row[$headerColMap['system_cash_amount']]) : $amount;
                $actCash = isset($headerColMap['actual_cash_amount']) ? (float) preg_replace('/[^0-9.]/', '', (string)$row[$headerColMap['actual_cash_amount']]) : $sysCash;
                $diff = abs($actCash - $sysCash);
                $duration = isset($headerColMap['duration_seconds']) ? (int) preg_replace('/[^0-9]/', '', (string)$row[$headerColMap['duration_seconds']]) : 60;
                $status = isset($headerColMap['status']) ? strtoupper(trim((string)($row[$headerColMap['status']] ?? 'SUCCESS'))) : 'SUCCESS';

                $matchedCashier = $cashiers->first(fn($c) => stripos($c->name, $cashierName) !== false) ?? $cashiers->firstWhere('position.name', 'Kasir') ?? $cashiers->first();

                // Skip if duplicate exists in DB
                $exists = CashierTransaction::where('transaction_number', $txNum)->where('period_id', $batch->period_id)->exists();
                if ($exists) continue;

                CashierTransaction::create([
                    'import_batch_id' => $batch->id,
                    'period_id' => $batch->period_id,
                    'cashier_employee_id' => $matchedCashier?->id,
                    'cashier_name_raw' => $cashierName,
                    'transaction_number' => $txNum,
                    'transaction_date' => $txDate,
                    'transaction_amount' => $amount,
                    'system_cash_amount' => $sysCash,
                    'actual_cash_amount' => $actCash,
                    'cash_difference' => $diff,
                    'duration_seconds' => $duration,
                    'status' => $status,
                    'is_duplicate' => false,
                ]);

                $committedCount++;

                // Aggregate metrics per cashier
                $cid = $matchedCashier?->id;
                if ($cid) {
                    if (!isset($cashierTotals[$cid])) {
                        $cashierTotals[$cid] = [
                            'total_tx' => 0,
                            'valid_tx' => 0,
                            'total_diff' => 0.0,
                            'duration_within_sla' => 0,
                        ];
                    }
                    $cashierTotals[$cid]['total_tx'] += 1;
                    if ($status === 'SUCCESS') $cashierTotals[$cid]['valid_tx'] += 1;
                    $cashierTotals[$cid]['total_diff'] += $diff;
                    if ($duration <= 180) $cashierTotals[$cid]['duration_within_sla'] += 1; // SLA 3 mins
                }
            }

            $batch->status = 'confirmed';
            $batch->confirmed_at = now();
            $batch->save();

            // Auto-populate Kasir KPI indicators (KSR-01, KSR-02, KSR-03, KSR-04)
            foreach ($cashierTotals as $empId => $totals) {
                $employeeKpi = EmployeeKpi::where('period_id', $batch->period_id)
                    ->where('employee_id', $empId)
                    ->first();

                if ($employeeKpi) {
                    // KSR-01 Akurasi Transaksi: (valid_tx / total_tx) * 100
                    $ksr01 = $employeeKpi->items()->where('definition_code_snapshot', 'KSR-01')->first();
                    if ($ksr01 && $totals['total_tx'] > 0) {
                        $ksr01->actual_decimal = round(($totals['valid_tx'] / $totals['total_tx']) * 100, 2);
                        $ksr01->status = 'verified';
                        $ksr01->save();
                        $this->calculationEngine->calculateItem($ksr01);
                    }

                    // KSR-02 Selisih Kas
                    $ksr02 = $employeeKpi->items()->where('definition_code_snapshot', 'KSR-02')->first();
                    if ($ksr02) {
                        $ksr02->actual_decimal = round($totals['total_diff'], 2);
                        $ksr02->status = 'verified';
                        $ksr02->save();
                        $this->calculationEngine->calculateItem($ksr02);
                    }

                    // KSR-03 Ketepatan Laporan Kas: 100% if before deadline
                    $ksr03 = $employeeKpi->items()->where('definition_code_snapshot', 'KSR-03')->first();
                    if ($ksr03) {
                        $isOntime = $batch->period->submission_deadline >= now();
                        $ksr03->actual_decimal = $isOntime ? 100.0 : 70.0;
                        $ksr03->status = 'verified';
                        $ksr03->save();
                        $this->calculationEngine->calculateItem($ksr03);
                    }

                    // KSR-04 Kecepatan Transaksi
                    $ksr04 = $employeeKpi->items()->where('definition_code_snapshot', 'KSR-04')->first();
                    if ($ksr04 && $totals['total_tx'] > 0) {
                        $ksr04->actual_decimal = round(($totals['duration_within_sla'] / $totals['total_tx']) * 100, 2);
                        $ksr04->status = 'verified';
                        $ksr04->save();
                        $this->calculationEngine->calculateItem($ksr04);
                    }

                    $employeeKpi->calculateProgress();
                }
            }

            AuditEvent::log(
                action: 'confirm_cashier_import',
                subjectType: 'ImportBatch',
                subjectId: (string) $batch->id,
                after: ['committed_rows' => $committedCount, 'cashiers_updated' => count($cashierTotals)],
                actorId: $confirmerId
            );

            return [
                'success' => true,
                'message' => "Import berhasil dikonfirmasi. {$committedCount} transaksi berhasil dicatat dan KPI Kasir telah diperbarui secara otomatis.",
                'batch' => $batch->fresh(),
            ];
        });
    }
}
