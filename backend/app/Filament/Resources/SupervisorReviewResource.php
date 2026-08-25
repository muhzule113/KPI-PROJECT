<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupervisorReviewResource\Pages;
use App\Models\EmployeeKpi;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SupervisorReviewResource extends Resource
{
    protected static ?string $model = EmployeeKpi::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Evaluasi & Penilaian';

    protected static ?string $navigationLabel = 'Review KPI Tim';

    protected static ?string $modelLabel = 'Review KPI';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['supervisor', 'super_admin']) ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->whereIn('status', ['submitted', 'under_review'])
            ->with(['employee', 'period']);

        $user = auth()->user();
        if ($user && !$user->hasRole('super_admin')) {
            $query->where('supervisor_id_snapshot', $user->employee?->id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('employee.position.name')
                    ->label('Jabatan'),
                Tables\Columns\TextColumn::make('period.name')
                    ->label('Periode')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn($state) => match ($state) {
                        'submitted' => 'Menunggu Review',
                        'under_review' => 'Sedang Direview',
                        default => ucfirst($state),
                    })
                    ->color(fn($state) => $state === 'submitted' ? 'warning' : 'info'),
                Tables\Columns\TextColumn::make('progress_percentage')
                    ->label('Progress')
                    ->suffix('%')
                    ->alignCenter(),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label('Buka Review')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->url(fn(EmployeeKpi $record) => static::getUrl('review', ['record' => $record])),
            ])
            ->emptyStateHeading('Tidak ada KPI menunggu review')
            ->emptyStateDescription('KPI tim yang sudah di-submit akan muncul di sini.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupervisorReviews::route('/'),
            'review' => Pages\ReviewKpiPage::route('/{record}/review'),
        ];
    }
}
