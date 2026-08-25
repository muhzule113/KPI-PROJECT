<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditEventResource\Pages;
use App\Models\AuditEvent;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AuditEventResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Sistem & Audit';

    protected static ?string $navigationLabel = 'Audit Log & Histori';

    protected static ?string $modelLabel = 'Log Audit';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return \App\Support\MenuAccess::can(
            auth()->user(),
            ['super_admin'],
            []
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('Waktu Kejadian')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('actor.name')
                    ->label('Pelaku (Actor)')
                    ->placeholder('System')
                    ->searchable(),
                Tables\Columns\TextColumn::make('action')
                    ->label('Aksi')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'approve_kpi' => 'success',
                        'open_period', 'publish_period' => 'info',
                        'return_kpi_to_supervisor', 'request_kpi_revision' => 'warning',
                        default => 'primary',
                    }),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Tipe Objek')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('subject_id')
                    ->label('ID Objek')
                    ->limit(15),
                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan / Catatan')
                    ->limit(40),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP Address'),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditEvents::route('/'),
        ];
    }
}
