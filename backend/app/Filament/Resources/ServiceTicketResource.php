<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceTicketResource\Pages;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\ServiceTicket;
use App\Modules\Assessment\OperationalKpiSyncService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ServiceTicketResource extends Resource
{
    protected static ?string $model = ServiceTicket::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'Manajemen Servis & Operasional';

    protected static ?string $navigationLabel = 'Tiket Servis HP';

    protected static ?string $modelLabel = 'Tiket Servis';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return \App\Support\MenuAccess::can(
            auth()->user(),
            [],
            ['POS-TEK', 'POS-CS', 'POS-GUD']
        );
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informasi Pelanggan & Perangkat')
                    ->schema([
                        Forms\Components\TextInput::make('ticket_number')
                            ->label('Nomor Tiket')
                            ->default(fn() => 'SRV-' . date('Ym') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT))
                            ->required()
                            ->disabled(),
                        Forms\Components\TextInput::make('customer_name')
                            ->label('Nama Pelanggan')
                            ->required()
                            ->maxLength(100),
                        Forms\Components\TextInput::make('customer_phone')
                            ->label('No. WhatsApp / HP')
                            ->required()
                            ->tel()
                            ->maxLength(30),
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('device_brand')
                                ->label('Merek HP')
                                ->required()
                                ->placeholder('e.g. Apple, Samsung, Xiaomi'),
                            Forms\Components\TextInput::make('device_model')
                                ->label('Tipe / Model HP')
                                ->required()
                                ->placeholder('e.g. iPhone 13 Pro Max'),
                        ]),
                        Forms\Components\TextInput::make('imei_or_serial')
                            ->label('IMEI / Serial Number')
                            ->maxLength(100),
                        Forms\Components\TextInput::make('passcode_or_pattern')
                            ->label('Pola / PIN Layar (Jika Diizinkan)')
                            ->maxLength(50),
                        Forms\Components\Textarea::make('initial_complaint')
                            ->label('Keluhan Kerusakan Awal')
                            ->required()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('physical_condition')
                            ->label('Kondisi Fisik Masuk')
                            ->placeholder('e.g. Lecet pemakaian di sudut kiri, LCD retak rambut')
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Penugasan & Biaya')
                    ->schema([
                        Forms\Components\Select::make('technician_employee_id')
                            ->label('Teknisi yang Ditugaskan')
                            ->relationship('technicianEmployee', 'name', fn($q) => $q->whereHas('position', fn($p) => $p->where('code', 'POS-TEK')))
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('status')
                            ->label('Status Pengerjaan')
                            ->options([
                                'intake' => 'Intake (Diterima CS)',
                                'diagnosing' => 'Sedang Diagnosa',
                                'waiting_sparepart' => 'Menunggu Sparepart',
                                'in_progress' => 'Sedang Dikerjakan',
                                'qc_ready' => 'Siap QC / Selesai Teknisi',
                                'completed' => 'Selesai Sukses',
                                'cancelled_unrepairable' => 'Batal / Tidak Dapat Diperbaiki',
                                'delivered' => 'Unit Diserahkan ke Pelanggan',
                            ])
                            ->default('intake')
                            ->required(),
                        Forms\Components\Select::make('result_status')
                            ->label('Hasil Servis')
                            ->options([
                                'pending' => 'Menunggu Hasil',
                                'success' => 'Berhasil Diperbaiki (Sukses)',
                                'unrepairable' => 'Tidak Dapat Diperbaiki (Gagal)',
                                'warranty_return' => 'Retur Garansi (Komplain Ulang)',
                            ])
                            ->default('pending'),
                        Forms\Components\TextInput::make('estimated_cost')
                            ->label('Estimasi Biaya (Rp)')
                            ->numeric()
                            ->prefix('Rp')
                            ->default(0),
                        Forms\Components\TextInput::make('final_cost')
                            ->label('Biaya Final (Rp)')
                            ->numeric()
                            ->prefix('Rp')
                            ->default(0),
                        Forms\Components\DateTimePicker::make('estimated_completion_at')
                            ->label('Estimasi Selesai')
                            ->default(now()->addDays(2)),
                    ])->columns(2),

                Forms\Components\Section::make('Catatan Pengerjaan Teknisi')
                    ->schema([
                        Forms\Components\Textarea::make('diagnosis_notes')
                            ->label('Hasil Diagnosa Teknisi')
                            ->placeholder('e.g. Kerusakan pada IC Power & Baterai kembung.'),
                        Forms\Components\Textarea::make('action_notes')
                            ->label('Tindakan Servis yang Dilakukan')
                            ->placeholder('e.g. Penggantian IC Power PM8150 dan pasang baterai original.'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ticket_number')
                    ->label('No. Tiket')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->searchable()
                    ->description(fn($record) => $record->customer_phone),
                Tables\Columns\TextColumn::make('device_brand')
                    ->label('Perangkat')
                    ->formatStateUsing(fn($record) => "{$record->device_brand} {$record->device_model}")
                    ->searchable(),
                Tables\Columns\TextColumn::make('technicianEmployee.name')
                    ->label('Teknisi')
                    ->placeholder('Belum Ditugaskan')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'completed', 'delivered' => 'success',
                        'in_progress', 'diagnosing' => 'primary',
                        'waiting_sparepart' => 'warning',
                        'cancelled_unrepairable' => 'danger',
                        default => 'secondary',
                    })
                    ->formatStateUsing(fn(string $state) => match ($state) {
                        'intake' => 'Diterima CS',
                        'diagnosing' => 'Diagnosa',
                        'waiting_sparepart' => 'Tunggu Part',
                        'in_progress' => 'Dikerjakan',
                        'qc_ready' => 'Siap QC',
                        'completed' => 'Selesai',
                        'delivered' => 'Diserahkan',
                        'cancelled_unrepairable' => 'Gagal / Batal',
                        default => ucfirst($state),
                    }),
                Tables\Columns\TextColumn::make('result_status')
                    ->label('Hasil')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'success' => 'success',
                        'warranty_return' => 'danger',
                        'unrepairable' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tgl Masuk')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'intake' => 'Diterima CS',
                        'in_progress' => 'Sedang Dikerjakan',
                        'completed' => 'Selesai',
                        'delivered' => 'Diserahkan',
                        'cancelled_unrepairable' => 'Gagal',
                    ]),
                Tables\Filters\SelectFilter::make('technician_employee_id')
                    ->label('Teknisi')
                    ->relationship('technicianEmployee', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('syncToKpi')
                    ->label('Sync ke KPI')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->action(function (ServiceTicket $record, OperationalKpiSyncService $syncService) {
                        $period = $record->period ?? \App\Models\KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
                        if ($period) {
                            $res = $syncService->syncPeriodOperationalData($period);
                            Notification::make()
                                ->title('Sinkronisasi KPI Sukses!')
                                ->body($res['message'])
                                ->success()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServiceTickets::route('/'),
            'create' => Pages\CreateServiceTicket::route('/create'),
            'edit' => Pages\EditServiceTicket::route('/{record}/edit'),
        ];
    }
}
