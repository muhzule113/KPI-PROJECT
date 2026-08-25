<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeKpiResource\Pages;
use App\Models\EmployeeKpi;
use App\Modules\Approval\ApprovalService;
use App\Modules\Calculation\KpiCalculationEngine;
use App\Modules\Review\ReviewService;
use Exception;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EmployeeKpiResource extends Resource
{
    protected static ?string $model = EmployeeKpi::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Evaluasi & Penilaian';

    protected static ?string $navigationLabel = 'Penilaian & Review KPI';

    protected static ?string $modelLabel = 'Penilaian Karyawan';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.name')
                    ->label('Nama Karyawan')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('employee.position.name')
                    ->label('Jabatan')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('period.name')
                    ->label('Periode')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'draft' => 'gray',
                        'submitted' => 'info',
                        'under_review' => 'warning',
                        'revision_required' => 'danger',
                        'verified' => 'primary',
                        'pending_approval' => 'warning',
                        'approved', 'locked' => 'success',
                        default => 'secondary',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'draft' => 'Draft',
                        'submitted' => 'Menunggu Review',
                        'under_review' => 'Sedang Direview',
                        'revision_required' => 'Perlu Revisi',
                        'verified' => 'Terverifikasi',
                        'pending_approval' => 'Menunggu Approval',
                        'approved' => 'Disetujui',
                        'locked' => 'Terkunci (Final)',
                        default => ucfirst($state),
                    }),
                Tables\Columns\TextColumn::make('progress_percentage')
                    ->label('Progress')
                    ->formatStateUsing(fn($state) => "{$state}%")
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('final_score')
                    ->label('Skor Akhir')
                    ->formatStateUsing(fn($state) => $state !== null ? number_format((float)$state, 2) : '-')
                    ->weight('bold')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('rating_label')
                    ->label('Predikat')
                    ->badge()
                    ->color(fn(?string $state): string => match (true) {
                        str_contains((string)$state, 'Istimewa') => 'success',
                        str_contains((string)$state, 'Sangat Baik') => 'info',
                        str_contains((string)$state, 'Baik') => 'primary',
                        str_contains((string)$state, 'Cukup') => 'warning',
                        str_contains((string)$state, 'Perbaikan') => 'danger',
                        default => 'secondary',
                    }),
                Tables\Columns\TextColumn::make('revision_number')
                    ->label('Revisi Ke-')
                    ->alignCenter(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('period_id')
                    ->label('Periode')
                    ->relationship('period', 'name'),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'submitted' => 'Menunggu Review',
                        'under_review' => 'Sedang Direview',
                        'revision_required' => 'Perlu Revisi',
                        'verified' => 'Terverifikasi',
                        'pending_approval' => 'Menunggu Approval',
                        'approved' => 'Disetujui',
                        'locked' => 'Terkunci',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('recalculate')
                    ->label('Hitung Skor')
                    ->icon('heroicon-o-calculator')
                    ->color('primary')
                    ->action(function (EmployeeKpi $record, KpiCalculationEngine $engine) {
                        $res = $engine->calculateKpi($record, 'manual_recalculation');
                        Notification::make()
                            ->title('Skor Berhasil Dihitung!')
                            ->body("Skor total: {$res['total_score']} ({$res['rating_label']})")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('approve')
                    ->label('Approve Final')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(EmployeeKpi $record) => in_array($record->status, ['pending_approval', 'verified']))
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('note')
                            ->label('Catatan Approval (Opsional)')
                            ->placeholder('Kinerja sangat memuaskan.'),
                    ])
                    ->action(function (EmployeeKpi $record, array $data, ApprovalService $approvalService) {
                        try {
                            $approvalService->approve($record, $data['note'] ?? null);
                            Notification::make()
                                ->title('KPI Berhasil Disetujui & Dikunci!')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Gagal Approve')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('returnToSpv')
                    ->label('Kembalikan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn(EmployeeKpi $record) => in_array($record->status, ['pending_approval']))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Alasan Pengembalian ke Supervisor')
                            ->required(),
                    ])
                    ->action(function (EmployeeKpi $record, array $data, ApprovalService $approvalService) {
                        try {
                            $approvalService->return($record, $data['reason']);
                            Notification::make()
                                ->title('KPI Dikembalikan ke Supervisor')
                                ->warning()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Gagal Mengembalikan KPI')
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
            'index' => Pages\ListEmployeeKpis::route('/'),
        ];
    }
}
