<?php

namespace App\Filament\Resources;

use App\Filament\Resources\KpiPeriodResource\Pages;
use App\Models\Branch;
use App\Models\KpiPeriod;
use App\Modules\Period\PeriodService;
use Exception;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class KpiPeriodResource extends Resource
{
    protected static ?string $model = KpiPeriod::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Evaluasi & Penilaian';

    protected static ?string $navigationLabel = 'Periode Penilaian';

    protected static ?string $modelLabel = 'Periode KPI';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informasi Periode')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Periode')
                            ->required()
                            ->placeholder('e.g. Periode Agustus 2026')
                            ->maxLength(100),
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('year')
                                ->label('Tahun')
                                ->numeric()
                                ->default(date('Y'))
                                ->required(),
                            Forms\Components\Select::make('month')
                                ->label('Bulan')
                                ->options([
                                    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                                    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                                    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
                                ])
                                ->default(date('n'))
                                ->required(),
                        ]),
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('start_date')
                                ->label('Tanggal Mulai')
                                ->default(now()->startOfMonth())
                                ->required(),
                            Forms\Components\DatePicker::make('end_date')
                                ->label('Tanggal Selesai')
                                ->default(now()->endOfMonth())
                                ->required(),
                        ]),
                    ]),

                Forms\Components\Section::make('Jadwal & Deadline')
                    ->schema([
                        Forms\Components\DateTimePicker::make('submission_deadline')
                            ->label('Batas Akhir Input Karyawan')
                            ->required(),
                        Forms\Components\DateTimePicker::make('review_deadline')
                            ->label('Batas Akhir Review Supervisor')
                            ->required(),
                        Forms\Components\DateTimePicker::make('approval_deadline')
                            ->label('Batas Akhir Approval Manager')
                            ->required(),
                    ]),

                Forms\Components\Section::make('Cakupan Cabang')
                    ->schema([
                        Forms\Components\CheckboxList::make('branches')
                            ->label('Cabang Berpartisipasi')
                            ->relationship('branches', 'name')
                            ->columns(2),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Periode')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'OPEN' => 'success',
                        'READY' => 'info',
                        'IN_REVIEW' => 'warning',
                        'WAITING_APPROVAL' => 'warning',
                        'PUBLISHED' => 'info',
                        'LOCKED' => 'gray',
                        'SUBMISSION_CLOSED' => 'gray',
                        default => 'secondary',
                    }),
                Tables\Columns\TextColumn::make('total_eligible_employees')
                    ->label('Karyawan Eligible')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('submission_deadline')
                    ->label('Deadline Input')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('approval_deadline')
                    ->label('Deadline Final')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('validateReadiness')
                    ->label('Cek Kesiapan')
                    ->icon('heroicon-o-shield-check')
                    ->color('info')
                    ->action(function (KpiPeriod $record, PeriodService $periodService) {
                        $readiness = $periodService->validateReadiness($record);
                        if ($readiness['is_ready']) {
                            Notification::make()
                                ->title('Periode Siap Dibuka!')
                                ->body("Seluruh {$readiness['eligible_count']} karyawan aktif memiliki template valid 100%.")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Periode Belum Siap')
                                ->body(implode("\n", $readiness['issues']))
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('openPeriod')
                    ->label('Buka Periode')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->visible(fn(KpiPeriod $record) => in_array($record->status, ['DRAFT', 'READY']))
                    ->requiresConfirmation()
                    ->modalHeading('Buka Periode Penilaian')
                    ->modalDescription('Sistem akan membuat snapshot indikator KPI untuk seluruh karyawan aktif dan mengirimkan notifikasi. Lanjutkan?')
                    ->action(function (KpiPeriod $record, PeriodService $periodService) {
                        try {
                            $periodService->openPeriod($record);
                            Notification::make()
                                ->title('Periode Berhasil Dibuka!')
                                ->body('Snapshot KPI telah dibuat dan periode sekarang berstatus OPEN.')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Gagal Membuka Periode')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('closeSubmission')
                    ->label('Tutup Pengisian (Submit)')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->visible(fn(KpiPeriod $record) => $record->status === 'OPEN')
                    ->requiresConfirmation()
                    ->modalHeading('Tutup Pengisian Periode')
                    ->modalDescription('Input nilai dari karyawan akan ditutup. Karyawan tidak bisa lagi mengirim/mengubah data. Lanjutkan?')
                    ->action(function (KpiPeriod $record, PeriodService $periodService) {
                        $periodService->closeSubmission($record);
                        Notification::make()->title('Pengisian Periode Ditutup (SUBMISSION_CLOSED).')->success()->send();
                    }),

                Tables\Actions\Action::make('publishPeriod')
                    ->label('Publish Hasil')
                    ->icon('heroicon-o-megaphone')
                    ->color('warning')
                    ->visible(fn(KpiPeriod $record) => in_array($record->status, ['OPEN', 'SUBMISSION_CLOSED', 'WAITING_APPROVAL']))
                    ->requiresConfirmation()
                    ->action(function (KpiPeriod $record, PeriodService $periodService) {
                        $periodService->publishPeriod($record);
                        Notification::make()->title('Hasil Periode Telah Dipublish!')->success()->send();
                    }),

                Tables\Actions\Action::make('lockPeriod')
                    ->label('Kunci Final (Lock)')
                    ->icon('heroicon-o-lock-closed')
                    ->color('gray')
                    ->visible(fn(KpiPeriod $record) => $record->status === 'PUBLISHED')
                    ->requiresConfirmation()
                    ->action(function (KpiPeriod $record, PeriodService $periodService) {
                        $periodService->lockPeriod($record);
                        Notification::make()->title('Periode Berhasil Dikunci (Locked).')->success()->send();
                    }),

                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKpiPeriods::route('/'),
            'create' => Pages\CreateKpiPeriod::route('/create'),
            'edit' => Pages\EditKpiPeriod::route('/{record}/edit'),
        ];
    }
}
