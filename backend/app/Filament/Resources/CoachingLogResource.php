<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CoachingLogResource\Pages;
use App\Models\CoachingLog;
use App\Models\Employee;
use App\Models\KpiPeriod;
use App\Modules\Assessment\CoachingKpiSyncService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CoachingLogResource extends Resource
{
    protected static ?string $model = CoachingLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Operasional Harian';

    protected static ?string $navigationLabel = 'Coaching & Evaluasi';

    protected static ?string $modelLabel = 'Coaching Log';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Sesi Coaching')
                    ->schema([
                        Forms\Components\Select::make('supervisor_id')
                            ->label('Supervisor')
                            ->relationship('supervisor', 'name')
                            ->searchable()
                            ->preload()
                            ->default(fn() => self::currentSupervisorId())
                            ->required(),
                        Forms\Components\Select::make('employee_id')
                            ->label('Karyawan yang Dicoach')
                            ->relationship('employee', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\DatePicker::make('coaching_date')
                            ->label('Tanggal Coaching')
                            ->required()
                            ->default(now()),
                        Forms\Components\TextInput::make('topic')
                            ->label('Topik / Materi')
                            ->required()
                            ->maxLength(150)
                            ->placeholder('mis. Teknik diagnosa IC power, komunikasi dengan pelanggan'),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan & Hasil')
                            ->rows(3),
                        Forms\Components\Toggle::make('target_met')
                            ->label('Target Coaching Tercapai')
                            ->helperText('Apakah evaluasi setelah coaching menunjukkan perbaikan / target tercapai?'),
                        Forms\Components\DatePicker::make('follow_up_date')
                            ->label('Tanggal Follow-up'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('coaching_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('supervisor.name')
                    ->label('Supervisor')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('employee.name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('topic')
                    ->label('Topik')
                    ->limit(40),
                Tables\Columns\IconColumn::make('target_met')
                    ->label('Target')
                    ->boolean(),
                Tables\Columns\TextColumn::make('follow_up_date')
                    ->label('Follow-up')
                    ->date('d M Y')
                    ->placeholder('-'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('supervisor_id')
                    ->label('Supervisor')
                    ->relationship('supervisor', 'name')
                    ->searchable(),
                Tables\Filters\Filter::make('coaching_date')
                    ->form([
                        Forms\Components\DatePicker::make('date_from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('date_until')->label('Sampai Tanggal'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['date_from'], fn($q, $d) => $q->whereDate('coaching_date', '>=', $d))
                            ->when($data['date_until'], fn($q, $d) => $q->whereDate('coaching_date', '<=', $d));
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

                        $res = app(CoachingKpiSyncService::class)->syncPeriodCoachingData($period);
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

    protected static function currentSupervisorId(): ?string
    {
        $employee = auth()->user()?->employee;

        return $employee?->position?->code === 'POS-SPV' ? $employee->id : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoachingLogs::route('/'),
            'create' => Pages\CreateCoachingLog::route('/create'),
            'edit' => Pages\EditCoachingLog::route('/{record}/edit'),
        ];
    }
}
