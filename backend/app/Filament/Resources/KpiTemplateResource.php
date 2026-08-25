<?php

namespace App\Filament\Resources;

use App\Filament\Resources\KpiTemplateResource\Pages;
use App\Models\KpiDefinition;
use App\Models\KpiRatingScheme;
use App\Models\KpiTemplate;
use App\Models\KpiTemplateItem;
use App\Models\KpiTemplateVersion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class KpiTemplateResource extends Resource
{
    protected static ?string $model = KpiTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Master KPI';

    protected static ?string $navigationLabel = 'Template KPI Jabatan';

    protected static ?string $modelLabel = 'Template KPI';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return \App\Support\MenuAccess::can(
            auth()->user(),
            ['super_admin'],
            []
        );
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informasi Template')
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label('Kode Template')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->placeholder('e.g. TPL-TEK-01')
                            ->maxLength(50),
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Template')
                            ->required()
                            ->placeholder('e.g. Template KPI Teknisi v1')
                            ->maxLength(150),
                        Forms\Components\Select::make('position_id')
                            ->label('Jabatan Sasaran')
                            ->relationship('position', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Status Aktif')
                            ->default(true),
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
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Template')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('position.name')
                    ->label('Jabatan')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('activeVersion.version_number')
                    ->label('Versi Aktif')
                    ->formatStateUsing(fn($state) => $state ? "v{$state}" : 'Belum ada')
                    ->badge()
                    ->color('success'),
                Tables\Columns\TextColumn::make('activeVersion.total_weight')
                    ->label('Total Bobot')
                    ->formatStateUsing(fn($state) => $state ? "{$state}%" : '0%')
                    ->color(fn($state) => abs((float)$state - 100.00) < 0.01 ? 'success' : 'danger')
                    ->weight('bold'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKpiTemplates::route('/'),
            'create' => Pages\CreateKpiTemplate::route('/create'),
            'edit' => Pages\EditKpiTemplate::route('/{record}/edit'),
        ];
    }
}
