<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BranchResource\Pages;
use App\Models\Branch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Organisasi';

    protected static ?string $navigationLabel = 'Cabang Toko';

    protected static ?string $modelLabel = 'Cabang';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')->label('Kode Cabang')->required()->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('name')->label('Nama Cabang')->required(),
                Forms\Components\TextInput::make('address')->label('Alamat'),
                Forms\Components\TextInput::make('phone')->label('Telepon'),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Kode')->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Nama Cabang')->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('address')->label('Alamat')->limit(40),
                Tables\Columns\TextColumn::make('employees_count')->label('Jumlah Karyawan')->counts('employees')->alignCenter(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBranches::route('/'),
            'create' => Pages\CreateBranch::route('/create'),
            'edit' => Pages\EditBranch::route('/{record}/edit'),
        ];
    }
}
