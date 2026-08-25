<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdminWorkLogResource\Pages;
use App\Models\AdminWorkLog;
use App\Models\Employee;
use App\Models\KpiPeriod;
use App\Modules\Assessment\AdminWorkLogKpiSyncService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AdminWorkLogResource extends Resource
{
    protected static ?string $model = AdminWorkLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Operasional Harian';

    protected static ?string $navigationLabel = 'Work-Log Admin';

    protected static ?string $modelLabel = 'Work-Log Admin';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return \App\Support\MenuAccess::can(
            auth()->user(),
            [],
            ['POS-ADM']
        );
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identitas & Periode')
                    ->schema([
                        Forms\Components\Select::make('employee_id')
                            ->label('Admin')
                            ->relationship('employee', 'name')
                            ->searchable()
                            ->preload()
                            ->default(fn() => self::currentAdminEmployeeId())
                            ->required(),
                        Forms\Components\DatePicker::make('work_date')
                            ->label('Tanggal Kerja')
                            ->required()
                            ->default(now()),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('ADM-01 · Akurasi Input Data (30%)')
                    ->description('Catat jumlah record yang diinput dan berapa yang kena koreksi/error. Sistem menghitung persentase akurasinya.')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('records_input')
                                ->label('Jumlah Record Diinput')
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                            Forms\Components\TextInput::make('records_corrected')
                                ->label('Jumlah Kena Koreksi / Error')
                                ->numeric()
                                ->minValue(0)
                                ->required()
                                ->rule('lte:records_input'),
                        ]),
                    ]),
                Forms\Components\Section::make('ADM-03 · Kelengkapan Dokumen (15%)')
                    ->description('Catat total dokumen yang wajib lengkap dan berapa yang sudah lengkap.')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('documents_eligible')
                                ->label('Total Dokumen Eligible')
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                            Forms\Components\TextInput::make('documents_complete')
                                ->label('Dokumen Lengkap')
                                ->numeric()
                                ->minValue(0)
                                ->required()
                                ->rule('lte:documents_eligible'),
                        ]),
                    ]),
                Forms\Components\Section::make('ADM-04 · Rekonsiliasi Data (15%)')
                    ->description('Catat jumlah rekonsiliasi yang dilakukan dan berapa yang hasilnya sesuai tanpa exception.')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('reconciliations_total')
                                ->label('Total Rekonsiliasi')
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                            Forms\Components\TextInput::make('reconciliations_success')
                                ->label('Rekonsiliasi Sukses / Sesuai')
                                ->numeric()
                                ->minValue(0)
                                ->required()
                                ->rule('lte:reconciliations_total'),
                        ]),
                    ]),
                Forms\Components\Section::make('Catatan & Bukti')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan (Opsional)')
                            ->rows(2)
                            ->maxLength(500),
                        Forms\Components\FileUpload::make('evidence_path')
                            ->label('Bukti / Evidence (Opsional)')
                            ->directory('admin-worklogs')
                            ->acceptedFileTypes(['application/pdf', 'image/*'])
                            ->maxSize(5120),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.name')
                    ->label('Admin')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('work_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('records_input')
                    ->label('Record')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('records_corrected')
                    ->label('Koreksi')
                    ->alignCenter()
                    ->color(fn($record) => $record->records_corrected > 0 ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('documents_complete')
                    ->label('Dok Lengkap')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('reconciliations_success')
                    ->label('Rekonsiliasi OK')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('period.name')
                    ->label('Periode')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('employee_id')
                    ->label('Admin')
                    ->relationship('employee', 'name')
                    ->searchable(),
                Tables\Filters\Filter::make('work_date')
                    ->form([
                        Forms\Components\DatePicker::make('date_from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('date_until')->label('Sampai Tanggal'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['date_from'], fn($q, $d) => $q->whereDate('work_date', '>=', $d))
                            ->when($data['date_until'], fn($q, $d) => $q->whereDate('work_date', '<=', $d));
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('syncKpi')
                    ->label('Sinkronkan ke KPI')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function () {
                        $period = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
                        if (!$period) {
                            Notification::make()->title('Tidak ada periode KPI yang sedang OPEN.')->warning()->send();

                            return;
                        }

                        $res = app(AdminWorkLogKpiSyncService::class)->syncPeriodWorkLogData($period);
                        Notification::make()->title($res['message'])->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function currentAdminEmployeeId(): ?string
    {
        $employee = auth()->user()?->employee;

        return $employee?->position?->code === 'POS-ADM' ? $employee->id : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdminWorkLogs::route('/'),
            'create' => Pages\CreateAdminWorkLog::route('/create'),
            'edit' => Pages\EditAdminWorkLog::route('/{record}/edit'),
        ];
    }
}
