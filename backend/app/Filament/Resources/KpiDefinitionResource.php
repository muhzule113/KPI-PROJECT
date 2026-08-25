<?php

namespace App\Filament\Resources;

use App\Filament\Resources\KpiDefinitionResource\Pages;
use App\Models\KpiDefinition;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class KpiDefinitionResource extends Resource
{
    protected static ?string $model = KpiDefinition::class;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = 'Master KPI';

    protected static ?string $navigationLabel = 'Katalog Indikator KPI';

    protected static ?string $modelLabel = 'Definisi Indikator';

    protected static ?int $navigationSort = 1;

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
                Forms\Components\Section::make('Definisi Indikator')
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label('Kode Indikator')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->placeholder('e.g. TEK-01')
                            ->maxLength(50),
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Indikator')
                            ->required()
                            ->placeholder('e.g. Jumlah Servis Selesai')
                            ->maxLength(150),
                        Forms\Components\Select::make('metric_type')
                            ->label('Tipe Metrik')
                            ->options([
                                'percentage' => 'Persentase (%)',
                                'count' => 'Jumlah / Unit',
                                'currency' => 'Mata Uang (Rp)',
                                'rubric' => 'Rubrik / Checklist Observasi',
                                'time' => 'Waktu / Durasi',
                            ])
                            ->default('percentage')
                            ->required(),
                        Forms\Components\TextInput::make('unit')
                            ->label('Satuan')
                            ->default('%')
                            ->required()
                            ->maxLength(30),
                        Forms\Components\Select::make('direction')
                            ->label('Arah Target')
                            ->options([
                                'higher' => 'Higher is Better (Semakin tinggi semakin baik)',
                                'lower' => 'Lower is Better (Semakin rendah semakin baik)',
                                'zero_tolerance' => 'Zero Tolerance (Toleransi Nol)',
                            ])
                            ->default('higher')
                            ->required(),
                        Forms\Components\Select::make('default_formula')
                            ->label('Formula Kalkulasi')
                            ->options([
                                'higher_is_better' => 'Higher is Better (Ratio)',
                                'lower_is_better' => 'Lower is Better (Threshold Penalty)',
                                'zero_tolerance' => 'Zero Tolerance (Full score & Failure limits)',
                                'rubric' => 'Rubric Checklist Scoring',
                            ])
                            ->default('higher_is_better')
                            ->required(),
                        Forms\Components\Select::make('source_type')
                            ->label('Sumber Data')
                            ->options([
                                'employee' => 'Input Karyawan',
                                'supervisor' => 'Observasi Supervisor',
                                'cross_role' => 'Cross-Role (CS / Admin)',
                                'import' => 'Import Laporan Kasir Otomatis',
                                'system' => 'Sistem Otomatis (Timestamp / Log)',
                            ])
                            ->default('employee')
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                        Forms\Components\Textarea::make('description')
                            ->label('Deskripsi & Petunjuk')
                            ->columnSpanFull(),
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
                    ->label('Nama Indikator')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('direction')
                    ->label('Arah Target')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'higher' => 'success',
                        'lower' => 'warning',
                        'zero_tolerance' => 'danger',
                        default => 'secondary',
                    }),
                Tables\Columns\TextColumn::make('source_type')
                    ->label('Sumber Data')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('unit')
                    ->label('Satuan')
                    ->alignCenter(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('direction')
                    ->options([
                        'higher' => 'Higher is Better',
                        'lower' => 'Lower is Better',
                        'zero_tolerance' => 'Zero Tolerance',
                    ]),
                Tables\Filters\SelectFilter::make('source_type')
                    ->options([
                        'employee' => 'Input Karyawan',
                        'supervisor' => 'Observasi Supervisor',
                        'cross_role' => 'Cross-Role',
                        'import' => 'Import Kasir',
                        'system' => 'Sistem Otomatis',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKpiDefinitions::route('/'),
            'create' => Pages\CreateKpiDefinition::route('/create'),
            'edit' => Pages\EditKpiDefinition::route('/{record}/edit'),
        ];
    }
}
