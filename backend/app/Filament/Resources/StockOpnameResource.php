<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockOpnameResource\Pages;
use App\Models\KpiPeriod;
use App\Models\StockOpname;
use App\Modules\Assessment\InventoryKpiSyncService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StockOpnameResource extends Resource
{
    protected static ?string $model = StockOpname::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Operasional Harian';

    protected static ?string $navigationLabel = 'Stock Opname Gudang';

    protected static ?string $modelLabel = 'Stock Opname';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informasi Sesi Opname')
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label('Kode Opname')
                            ->placeholder('Otomatis (contoh: OPN-202608-001)')
                            ->maxLength(50),
                        Forms\Components\Select::make('period_id')
                            ->label('Periode KPI')
                            ->relationship('period', 'name')
                            ->default(fn() => KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first()?->id)
                            ->required(),
                        Forms\Components\DatePicker::make('deadline')
                            ->label('Deadline Penyelesaian')
                            ->default(now()->addDays(3)),
                    ])
                    ->columns(3),
                Forms\Components\Section::make('Hasil Opname Per Item')
                    ->description('Stok sistem otomatis diambil dari database saat opname dibuat. Isi "Stok Fisik" sesuai hasil hitungan di gudang.')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->label('')
                            ->relationship()
                            ->schema([
                                Forms\Components\Select::make('sparepart_id')
                                    ->label('Sparepart')
                                    ->relationship('sparepart', 'name')
                                    ->disabled()
                                    ->dehydrated(false),
                                Forms\Components\TextInput::make('system_stock')
                                    ->label('Stok Sistem')
                                    ->disabled()
                                    ->dehydrated(false),
                                Forms\Components\TextInput::make('physical_stock')
                                    ->label('Stok Fisik (Hasil Hitung)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),
                            ])
                            ->columns(3)
                            ->reorderable(false)
                            ->addable(false)
                            ->deletable(false)
                            ->collapsible()
                            ->defaultItems(0),
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
                Tables\Columns\TextColumn::make('period.name')
                    ->label('Periode KPI')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn($state) => StockOpname::statusLabel($state))
                    ->color(fn($state) => match ($state) {
                        StockOpname::STATUS_COMPLETED => 'success',
                        StockOpname::STATUS_IN_PROGRESS => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Item')
                    ->counts('items'),
                Tables\Columns\TextColumn::make('deadline')
                    ->label('Deadline')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Selesai')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        StockOpname::STATUS_DRAFT => 'Draft',
                        StockOpname::STATUS_IN_PROGRESS => 'Sedang Berjalan',
                        StockOpname::STATUS_COMPLETED => 'Selesai',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('syncKpi')
                    ->label('Sinkronkan KPI Gudang')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function () {
                        $period = KpiPeriod::where('status', 'OPEN')->orderByDesc('id')->first();
                        if (!$period) {
                            Notification::make()->title('Tidak ada periode KPI yang sedang OPEN.')->warning()->send();

                            return;
                        }

                        $res = app(InventoryKpiSyncService::class)->syncPeriodInventoryData($period);
                        Notification::make()->title($res['message'])->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockOpnames::route('/'),
            'create' => Pages\CreateStockOpname::route('/create'),
            'edit' => Pages\EditStockOpname::route('/{record}/edit'),
        ];
    }
}
