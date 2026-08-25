<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SparepartResource\Pages;
use App\Models\Sparepart;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SparepartResource extends Resource
{
    protected static ?string $model = Sparepart::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Manajemen Servis & Operasional';

    protected static ?string $navigationLabel = 'Katalog Produk & Stok';

    protected static ?string $modelLabel = 'Produk';

    protected static ?string $pluralModelLabel = 'Produk';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return \App\Support\MenuAccess::can(
            auth()->user(),
            [],
            ['POS-GUD']
        );
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informasi Produk')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('code')
                                ->label('Kode Produk')
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->placeholder('e.g. PRT-LCD-IP13 / HS-IP13-128'),
                            Forms\Components\Select::make('product_type')
                                ->label('Jenis Produk')
                                ->options(Sparepart::TYPES)
                                ->default(Sparepart::TYPE_SPAREPART)
                                ->required()
                                ->live(),
                        ]),
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Produk')
                            ->required()
                            ->placeholder('e.g. LCD Screen Assembly iPhone 13 Original OEM'),
                        Forms\Components\Select::make('category')
                            ->label('Kategori')
                            ->options(fn(Forms\Get $get) => $get('product_type') === Sparepart::TYPE_SPAREPART
                                ? [
                                    'LCD' => 'LCD & Layar Sentuh',
                                    'Baterai' => 'Baterai',
                                    'IC' => 'Komponen IC / Chipset',
                                    'Fleksibel' => 'Kabel Fleksibel (Flex Cable)',
                                    'Kamera' => 'Modul Kamera',
                                    'Speaker' => 'Speaker / Buzzer / Mic',
                                    'Housing' => 'Housing & Backdoor',
                                    'Port' => 'Port Charger / Lightning',
                                ]
                                : [
                                    'Handset' => 'Handset HP',
                                    'Tablet' => 'Tablet / iPad',
                                    'Aksesoris' => 'Aksesoris',
                                    'Lainnya' => 'Lainnya',
                                ])
                            ->required(),
                        Forms\Components\TextInput::make('compatible_models')
                            ->label('Model Kompatibel (Opsional)')
                            ->placeholder('e.g. iPhone 13, iPhone 13 Pro'),
                        Forms\Components\Grid::make(3)->schema([
                            Forms\Components\TextInput::make('stock_quantity')
                                ->label('Stok Saat Ini')
                                ->numeric()
                                ->default(0)
                                ->required(),
                            Forms\Components\TextInput::make('min_stock_alert')
                                ->label('Batas Minimum Stok')
                                ->numeric()
                                ->default(5)
                                ->required(),
                            Forms\Components\Toggle::make('is_critical')
                                ->label('Produk Kritis (Wajib Tersedia)')
                                ->default(false),
                        ]),
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('purchase_price')
                                ->label('Harga Beli / Modal (Rp)')
                                ->numeric()
                                ->prefix('Rp')
                                ->default(0),
                            Forms\Components\TextInput::make('selling_price')
                                ->label('Harga Jual (Rp)')
                                ->numeric()
                                ->prefix('Rp')
                                ->default(0),
                        ]),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Produk')
                    ->searchable()
                    ->description(fn($record) => $record->compatible_models),
                Tables\Columns\TextColumn::make('product_type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn($state) => Sparepart::TYPES[$state] ?? ucfirst($state))
                    ->color(fn($state) => match ($state) {
                        Sparepart::TYPE_HANDSET => 'primary',
                        Sparepart::TYPE_TABLET => 'info',
                        Sparepart::TYPE_ACCESSORY => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('stock_quantity')
                    ->label('Stok')
                    ->alignCenter()
                    ->weight('bold')
                    ->color(fn($record) => $record->stock_quantity <= $record->min_stock_alert ? 'danger' : 'success'),
                Tables\Columns\IconColumn::make('is_critical')
                    ->label('Kritis')
                    ->boolean(),
                Tables\Columns\TextColumn::make('selling_price')
                    ->label('Harga Jual')
                    ->money('IDR')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('product_type')
                    ->label('Jenis Produk')
                    ->options(Sparepart::TYPES),
                Tables\Filters\SelectFilter::make('category')
                    ->options([
                        'LCD' => 'LCD',
                        'Baterai' => 'Baterai',
                        'IC' => 'IC / Chipset',
                        'Fleksibel' => 'Fleksibel',
                        'Kamera' => 'Kamera',
                        'Handset' => 'Handset HP',
                        'Tablet' => 'Tablet / iPad',
                        'Aksesoris' => 'Aksesoris',
                    ]),
                Tables\Filters\TernaryFilter::make('is_critical')
                    ->label('Produk Kritis'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('restock')
                    ->label('Restok')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('quantity')
                            ->label('Jumlah Masuk')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        Forms\Components\TextInput::make('note')
                            ->label('Catatan (Opsional)')
                            ->placeholder('mis. PO #INV-2026-001 dari supplier'),
                    ])
                    ->action(function ($record, array $data) {
                        $qty = (int) $data['quantity'];
                        $before = $record->stock_quantity;

                        \App\Models\StockMovement::create([
                            'sparepart_id' => $record->id,
                            'movement_type' => \App\Models\StockMovement::TYPE_RESTOCK_IN,
                            'quantity' => $qty,
                            'stock_before' => $before,
                            'stock_after' => $before + $qty,
                            'reference_type' => 'manual_restock',
                            'note' => $data['note'] ?? null,
                            'user_id' => auth()->id(),
                        ]);

                        $record->stock_quantity = $before + $qty;
                        $record->save();

                        Notification::make()
                            ->title("Stok {$record->name} bertambah {$qty} (total {$record->stock_quantity}).")
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSpareparts::route('/'),
            'create' => Pages\CreateSparepart::route('/create'),
            'edit' => Pages\EditSparepart::route('/{record}/edit'),
        ];
    }
}
