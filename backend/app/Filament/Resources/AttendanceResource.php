<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AttendanceResource\Pages;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\KpiPeriod;
use App\Modules\Assessment\AttendanceKpiSyncService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AttendanceResource extends Resource
{
    protected static ?string $model = Attendance::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Operasional Harian';

    protected static ?string $navigationLabel = 'Absensi & Kehadiran';

    protected static ?string $modelLabel = 'Absensi';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return \App\Support\MenuAccess::can(
            auth()->user(),
            ['owner_manager'],
            ['POS-ADM']
        );
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Data Kehadiran')
                    ->schema([
                        Forms\Components\Select::make('employee_id')
                            ->label('Karyawan')
                            ->relationship('employee', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\DatePicker::make('attendance_date')
                            ->label('Tanggal')
                            ->required()
                            ->default(now()),
                        Forms\Components\Select::make('status')
                            ->label('Status Kehadiran')
                            ->options([
                                Attendance::STATUS_PRESENT => 'Hadir',
                                Attendance::STATUS_LATE => 'Terlambat',
                                Attendance::STATUS_PERMISSION => 'Izin',
                                Attendance::STATUS_SICK_LEAVE => 'Sakit',
                                Attendance::STATUS_ABSENT => 'Alpha',
                            ])
                            ->required(),
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TimePicker::make('check_in_time')
                                ->label('Jam Masuk')
                                ->seconds(false),
                            Forms\Components\TimePicker::make('check_out_time')
                                ->label('Jam Keluar')
                                ->seconds(false),
                        ]),
                        Forms\Components\Textarea::make('note')
                            ->label('Catatan')
                            ->rows(2),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('attendance_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn($state) => Attendance::statusLabel($state))
                    ->color(fn($state) => match ($state) {
                        Attendance::STATUS_PRESENT => 'success',
                        Attendance::STATUS_LATE => 'warning',
                        Attendance::STATUS_PERMISSION, Attendance::STATUS_SICK_LEAVE => 'info',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('check_in_time')
                    ->label('Jam Masuk')
                    ->time('H:i'),
                Tables\Columns\TextColumn::make('check_out_time')
                    ->label('Jam Keluar')
                    ->time('H:i'),
                Tables\Columns\TextColumn::make('note')
                    ->label('Catatan')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        Attendance::STATUS_PRESENT => 'Hadir',
                        Attendance::STATUS_LATE => 'Terlambat',
                        Attendance::STATUS_PERMISSION => 'Izin',
                        Attendance::STATUS_SICK_LEAVE => 'Sakit',
                        Attendance::STATUS_ABSENT => 'Alpha',
                    ]),
                Tables\Filters\Filter::make('attendance_date')
                    ->form([
                        Forms\Components\DatePicker::make('date_from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('date_until')->label('Sampai Tanggal'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['date_from'], fn($q, $d) => $q->whereDate('attendance_date', '>=', $d))
                            ->when($data['date_until'], fn($q, $d) => $q->whereDate('attendance_date', '<=', $d));
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('fillToday')
                    ->label('Tandai Semua Hadir Hari Ini')
                    ->icon('heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->action(function () {
                        $date = now()->toDateString();
                        $created = 0;
                        Employee::where('status', 'active')->get()->each(function ($emp) use ($date, &$created) {
                            $exists = Attendance::where('employee_id', $emp->id)
                                ->where('attendance_date', $date)
                                ->exists();
                            if (!$exists) {
                                Attendance::create([
                                    'employee_id' => $emp->id,
                                    'branch_id' => $emp->branch_id,
                                    'attendance_date' => $date,
                                    'status' => Attendance::STATUS_PRESENT,
                                    'check_in_time' => now()->format('H:i:s'),
                                    'recorded_by' => auth()->id(),
                                ]);
                                $created++;
                            }
                        });

                        self::syncAttendanceToKpi();

                        Notification::make()
                            ->title("{$created} karyawan ditandai hadir hari ini.")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('syncKpi')
                    ->label('Sinkronkan ke KPI')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(fn() => self::syncAttendanceToKpi()),
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

    protected static function syncAttendanceToKpi(): void
    {
        $period = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
        if (!$period) {
            Notification::make()->title('Tidak ada periode KPI yang sedang OPEN.')->warning()->send();

            return;
        }

        $res = app(AttendanceKpiSyncService::class)->syncPeriodAttendanceData($period);
        Notification::make()->title($res['message'])->success()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendances::route('/'),
            'create' => Pages\CreateAttendance::route('/create'),
            'edit' => Pages\EditAttendance::route('/{record}/edit'),
        ];
    }
}
