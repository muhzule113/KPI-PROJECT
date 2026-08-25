<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ImportBatchResource\Pages;
use App\Models\ImportBatch;
use App\Models\KpiPeriod;
use App\Modules\Import\CashierImportService;
use Exception;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ImportBatchResource extends Resource
{
    protected static ?string $model = ImportBatch::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationGroup = 'Import & Rekonsiliasi';

    protected static ?string $navigationLabel = 'Import Laporan Kasir';

    protected static ?string $modelLabel = 'Batch Import Kasir';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return \App\Support\MenuAccess::can(
            auth()->user(),
            [],
            ['POS-KSR']
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('file_name')
                    ->label('Nama File')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('period.name')
                    ->label('Periode')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'confirmed' => 'success',
                        'ready_for_preview' => 'info',
                        'parsing' => 'warning',
                        'failed' => 'danger',
                        default => 'secondary',
                    }),
                Tables\Columns\TextColumn::make('total_rows')
                    ->label('Total Baris')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('valid_rows')
                    ->label('Valid')
                    ->color('success')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('duplicate_rows')
                    ->label('Duplikat')
                    ->color('warning')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('error_rows')
                    ->label('Error')
                    ->color('danger')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diunggah Pada')
                    ->dateTime('d M Y H:i'),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm')
                    ->label('Konfirmasi & Terapkan')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn(ImportBatch $record) => $record->status === 'ready_for_preview')
                    ->requiresConfirmation()
                    ->modalHeading('Konfirmasi Import Transaksi Kasir')
                    ->modalDescription('Sistem akan mencatat seluruh transaksi dan memperbarui nilai indikator KPI Kasir (KSR-01 s/d KSR-04) secara otomatis. Lanjutkan?')
                    ->action(function (ImportBatch $record, CashierImportService $importService) {
                        try {
                            $res = $importService->confirmAndCommit($record);
                            Notification::make()
                                ->title('Import Berhasil Diterapkan!')
                                ->body($res['message'])
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Gagal Menerapkan Import')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportBatches::route('/'),
        ];
    }
}
