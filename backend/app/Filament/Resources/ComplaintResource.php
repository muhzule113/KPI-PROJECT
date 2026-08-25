<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ComplaintResource\Pages;
use App\Models\Complaint;
use App\Models\KpiPeriod;
use App\Modules\Assessment\ComplaintKpiSyncService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ComplaintResource extends Resource
{
    protected static ?string $model = Complaint::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Operasional Harian';

    protected static ?string $navigationLabel = 'Komplain & Retur';

    protected static ?string $modelLabel = 'Komplain';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return \App\Support\MenuAccess::can(
            auth()->user(),
            ['owner_manager', 'supervisor'],
            []
        );
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informasi Komplain')
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label('Kode Komplain')
                            ->placeholder('Otomatis (contoh: CMP-202608-001)')
                            ->maxLength(50),
                        Forms\Components\DatePicker::make('complaint_date')
                            ->label('Tanggal Komplain')
                            ->required()
                            ->default(now()),
                        Forms\Components\Select::make('employee_id')
                            ->label('Karyawan Terkait (Subjek)')
                            ->relationship('employee', 'name')
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('service_ticket_id')
                            ->label('Tiket Servis Terkait (Opsional)')
                            ->relationship('serviceTicket', 'ticket_number')
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Detail & Penanganan')
                    ->schema([
                        Forms\Components\Select::make('channel')
                            ->label('Kanal')
                            ->options([
                                'in_store' => 'Di Toko',
                                'phone' => 'Telepon',
                                'whatsapp' => 'WhatsApp',
                                'google_review' => 'Google Review',
                                'other' => 'Lainnya',
                            ])
                            ->required(),
                        Forms\Components\Select::make('category')
                            ->label('Kategori')
                            ->options([
                                'service' => 'Servis',
                                'product' => 'Produk / Sparepart',
                                'cashier' => 'Kasir',
                                'general' => 'Umum',
                            ])
                            ->nullable(),
                        Forms\Components\Select::make('severity')
                            ->label('Severity')
                            ->options([
                                'low' => 'Rendah',
                                'medium' => 'Sedang',
                                'high' => 'Tinggi',
                            ])
                            ->default('medium')
                            ->required(),
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                Complaint::STATUS_OPEN => 'Terbuka',
                                Complaint::STATUS_IN_PROGRESS => 'Ditindaklanjuti',
                                Complaint::STATUS_RESOLVED => 'Terselesaikan',
                                Complaint::STATUS_CLOSED => 'Ditutup',
                            ])
                            ->default(Complaint::STATUS_OPEN)
                            ->required(),
                        Forms\Components\Textarea::make('description')
                            ->label('Deskripsi Komplain')
                            ->required()
                            ->rows(3),
                        Forms\Components\DateTimePicker::make('sla_deadline')
                            ->label('Batas SLA Penyelesaian')
                            ->default(now()->addDays(3)),
                        Forms\Components\DateTimePicker::make('resolved_at')
                            ->label('Waktu Terselesaikan'),
                        Forms\Components\Textarea::make('resolution_notes')
                            ->label('Catatan Penyelesaian')
                            ->rows(2),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('complaint_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('employee.name')
                    ->label('Subjek')
                    ->placeholder('-')
                    ->searchable(),
                Tables\Columns\TextColumn::make('channel')
                    ->label('Kanal')
                    ->formatStateUsing(fn($state) => Complaint::channelLabel($state)),
                Tables\Columns\TextColumn::make('severity')
                    ->label('Severity')
                    ->badge()
                    ->color(fn($state) => match ($state) {
                        'high' => 'danger',
                        'medium' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn($state) => Complaint::statusLabel($state))
                    ->color(fn($state) => match ($state) {
                        Complaint::STATUS_RESOLVED, Complaint::STATUS_CLOSED => 'success',
                        Complaint::STATUS_IN_PROGRESS => 'warning',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('resolved_at')
                    ->label('Selesai')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        Complaint::STATUS_OPEN => 'Terbuka',
                        Complaint::STATUS_IN_PROGRESS => 'Ditindaklanjuti',
                        Complaint::STATUS_RESOLVED => 'Terselesaikan',
                        Complaint::STATUS_CLOSED => 'Ditutup',
                    ]),
                Tables\Filters\SelectFilter::make('severity')
                    ->options([
                        'low' => 'Rendah',
                        'medium' => 'Sedang',
                        'high' => 'Tinggi',
                    ]),
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

                        $res = app(ComplaintKpiSyncService::class)->syncPeriodComplaintData($period);
                        Notification::make()->title($res['message'])->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('resolve')
                    ->label('Tandai Selesai')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn($record) => !in_array($record->status, [Complaint::STATUS_RESOLVED, Complaint::STATUS_CLOSED]))
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->status = Complaint::STATUS_RESOLVED;
                        $record->resolved_at = now();
                        $record->save();

                        Notification::make()->title('Komplain ditandai terselesaikan.')->success()->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComplaints::route('/'),
            'create' => Pages\CreateComplaint::route('/create'),
            'edit' => Pages\EditComplaint::route('/{record}/edit'),
        ];
    }
}
