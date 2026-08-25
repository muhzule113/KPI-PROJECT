<?php

namespace App\Filament\Resources;

use App\Filament\Resources\KpiCorrectionRequestResource\Pages;
use App\Models\KpiCorrectionRequest;
use App\Modules\Approval\ApprovalService;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class KpiCorrectionRequestResource extends Resource
{
    protected static ?string $model = KpiCorrectionRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Evaluasi & Penilaian';

    protected static ?string $navigationLabel = 'Koreksi KPI (Locked)';

    protected static ?string $modelLabel = 'Permintaan Koreksi';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['owner_manager', 'super_admin', 'supervisor']) ?? false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\TextEntry::make('employeeKpi.employee.name')
                    ->label('Karyawan'),
                Infolists\Components\TextEntry::make('employeeKpi.period.name')
                    ->label('Periode'),
                Infolists\Components\TextEntry::make('requester.name')
                    ->label('Diajukan Oleh'),
                Infolists\Components\TextEntry::make('reason')
                    ->label('Alasan Koreksi')
                    ->columnSpanFull(),
                Infolists\Components\TextEntry::make('before_json')
                    ->label('Kondisi Sebelum')
                    ->formatStateUsing(fn($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                    ->monospace()
                    ->columnSpanFull(),
                Infolists\Components\TextEntry::make('after_json')
                    ->label('Koreksi yang Diajukan')
                    ->formatStateUsing(fn($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                    ->monospace()
                    ->columnSpanFull(),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        $isApprover = fn() => auth()->user()?->hasAnyRole(['owner_manager', 'super_admin']) ?? false;

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employeeKpi.employee.name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('employeeKpi.period.name')
                    ->label('Periode'),
                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->limit(50),
                Tables\Columns\TextColumn::make('requester.name')
                    ->label('Diajukan Oleh'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn($state) => match ($state) {
                        'pending' => 'Menunggu Persetujuan',
                        'applied' => 'Telah Diterapkan',
                        'rejected' => 'Ditolak',
                        default => ucfirst($state),
                    })
                    ->color(fn($state) => match ($state) {
                        'pending' => 'warning',
                        'applied' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('applied_at')
                    ->label('Diterapkan')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Menunggu Persetujuan',
                        'applied' => 'Telah Diterapkan',
                        'rejected' => 'Ditolak',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Detail')
                    ->infolist(fn(Infolist $infolist) => static::infolist($infolist)),

                Tables\Actions\Action::make('approve')
                    ->label('Setujui & Terapkan')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(KpiCorrectionRequest $record) => $record->status === 'pending' && $isApprover())
                    ->requiresConfirmation()
                    ->modalHeading('Setujui Koreksi?')
                    ->modalDescription('Nilai aktual akan diperbaiki, skor dihitung ulang, dan KPI diperbarui. Dicatat di audit trail.')
                    ->action(function (KpiCorrectionRequest $record) {
                        try {
                            app(ApprovalService::class)->approveCorrection($record, auth()->id());
                            Notification::make()->title('Koreksi diterapkan dan KPI diperbarui.')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title('Gagal menyetujui')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn(KpiCorrectionRequest $record) => $record->status === 'pending' && $isApprover())
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Alasan Penolakan (Opsional)')
                            ->rows(2),
                    ])
                    ->action(function (KpiCorrectionRequest $record, array $data) {
                        app(ApprovalService::class)->rejectCorrection($record, auth()->id(), $data['reason'] ?? null);
                        Notification::make()->title('Permintaan koreksi ditolak.')->warning()->send();
                    }),
            ])
            ->emptyStateHeading('Tidak ada permintaan koreksi')
            ->emptyStateDescription('Koreksi resmi untuk KPI yang sudah approved/locked akan muncul di sini.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKpiCorrectionRequests::route('/'),
        ];
    }
}
