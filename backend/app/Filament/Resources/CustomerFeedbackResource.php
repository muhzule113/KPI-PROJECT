<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerFeedbackResource\Pages;
use App\Models\CustomerFeedback;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerFeedbackResource extends Resource
{
    protected static ?string $model = CustomerFeedback::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationGroup = 'Manajemen Servis & Operasional';

    protected static ?string $navigationLabel = 'Kepuasan Pelanggan (CSAT)';

    protected static ?string $modelLabel = 'Feedback Pelanggan';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return \App\Support\MenuAccess::can(
            auth()->user(),
            [],
            ['POS-CS']
        );
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Feedback & Kepuasan')
                    ->schema([
                        Forms\Components\Select::make('service_ticket_id')
                            ->label('Tiket Servis Terkait')
                            ->relationship('ticket', 'ticket_number')
                            ->required()
                            ->searchable(),
                        Forms\Components\Select::make('cs_employee_id')
                            ->label('Petugas CS')
                            ->relationship('csEmployee', 'name')
                            ->required(),
                        Forms\Components\TextInput::make('customer_name')
                            ->label('Nama Pelanggan')
                            ->required(),
                        Forms\Components\Select::make('rating')
                            ->label('Rating Bintang')
                            ->options([
                                5 => '⭐⭐⭐⭐⭐ 5 Bintang (Sangat Puas)',
                                4 => '⭐⭐⭐⭐ 4 Bintang (Puas)',
                                3 => '⭐⭐⭐ 3 Bintang (Cukup)',
                                2 => '⭐⭐ 2 Bintang (Kurang Puas)',
                                1 => '⭐ 1 Bintang (Kecewa / Komplain)',
                            ])
                            ->default(5)
                            ->required(),
                        Forms\Components\Textarea::make('comments')
                            ->label('Ulasan / Komentar Pelanggan')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ticket.ticket_number')
                    ->label('No. Tiket')
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rating')
                    ->label('Rating')
                    ->badge()
                    ->color(fn(int $state): string => match ($state) {
                        5 => 'success',
                        4 => 'info',
                        3 => 'primary',
                        2 => 'warning',
                        default => 'danger',
                    })
                    ->formatStateUsing(fn(int $state) => "⭐ {$state} / 5"),
                Tables\Columns\TextColumn::make('csEmployee.name')
                    ->label('Petugas CS')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('comments')
                    ->label('Ulasan')
                    ->limit(40),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('rating')
                    ->options([
                        5 => '5 Bintang',
                        4 => '4 Bintang',
                        3 => '3 Bintang',
                        2 => '2 Bintang',
                        1 => '1 Bintang',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomerFeedbacks::route('/'),
            'create' => Pages\CreateCustomerFeedback::route('/create'),
            'edit' => Pages\EditCustomerFeedback::route('/{record}/edit'),
        ];
    }
}
