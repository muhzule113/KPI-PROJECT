<?php

namespace App\Filament\Resources\ImportBatchResource\Pages;

use App\Filament\Resources\ImportBatchResource;
use App\Models\KpiPeriod;
use App\Modules\Import\CashierImportService;
use Exception;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListImportBatches extends ListRecords
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('uploadReport')
                ->label('Upload Laporan Kasir')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->form([
                    Forms\Components\Select::make('period_id')
                        ->label('Periode KPI')
                        ->options(KpiPeriod::pluck('name', 'id'))
                        ->default(fn() => KpiPeriod::where('status', 'OPEN')->value('id'))
                        ->required(),
                    Forms\Components\FileUpload::make('report_file')
                        ->label('File Laporan Kasir (XLSX / CSV)')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/csv',
                            'text/plain',
                            'application/vnd.ms-excel',
                        ])
                        ->required()
                        ->disk('local')
                        ->directory('imports/temp'),
                ])
                ->action(function (array $data, CashierImportService $importService) {
                    try {
                        $period = KpiPeriod::findOrFail($data['period_id']);
                        $filePath = storage_path('app/' . $data['report_file']);
                        
                        $uploadedFile = new \Illuminate\Http\UploadedFile(
                            $filePath,
                            basename($data['report_file']),
                            mime_content_type($filePath) ?: 'application/octet-stream',
                            null,
                            true
                        );

                        $batch = $importService->uploadAndStage($uploadedFile, $period);

                        Notification::make()
                            ->title('File Berhasil Diunggah & Dianalisis!')
                            ->body("Terdeteksi {$batch->total_rows} baris ({$batch->valid_rows} valid, {$batch->duplicate_rows} duplikat). Silakan periksa dan konfirmasi.")
                            ->success()
                            ->send();
                    } catch (Exception $e) {
                        Notification::make()
                            ->title('Gagal Mengunggah File')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
