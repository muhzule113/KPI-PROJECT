<?php

namespace App\Filament\Resources\SupervisorReviewResource\Pages;

use App\Filament\Resources\SupervisorReviewResource;
use App\Models\EmployeeKpi;
use App\Models\EmployeeKpiItem;
use App\Modules\Review\ReviewService;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class ReviewKpiPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string $resource = SupervisorReviewResource::class;

    protected static string $view = 'filament.resources.supervisor-review-resource.pages.review-kpi-page';

    public EmployeeKpi $record;

    public function mount(): void
    {
        // Pastikan hanya supervisor KPI ini (atau super_admin) yang bisa membuka
        $user = auth()->user();
        if ($user && !$user->hasRole('super_admin')) {
            abort_unless($this->record->supervisor_id_snapshot === $user->employee?->id, 403);
        }
        abort_unless(in_array($this->record->status, ['submitted', 'under_review']), 409);
    }

    public function getTableQuery(): Builder
    {
        return $this->record->items()->getQuery();
    }

    public function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('definition_code_snapshot')
                ->label('Kode')
                ->badge()
                ->color('primary'),
            Tables\Columns\TextColumn::make('name_snapshot')
                ->label('Indikator')
                ->searchable(),
            Tables\Columns\TextColumn::make('weight_snapshot')
                ->label('Bobot')
                ->suffix('%')
                ->alignCenter(),
            Tables\Columns\TextColumn::make('target_value_snapshot')
                ->label('Target')
                ->formatStateUsing(fn($state, EmployeeKpiItem $record) => "{$state} {$record->target_unit_snapshot}"),
            Tables\Columns\TextColumn::make('actual_decimal')
                ->label('Aktual')
                ->placeholder('-'),
            Tables\Columns\TextColumn::make('achievement_percentage')
                ->label('Pencapaian')
                ->suffix('%')
                ->placeholder('-')
                ->alignCenter(),
            Tables\Columns\TextColumn::make('status')
                ->label('Status Item')
                ->badge()
                ->formatStateUsing(fn($state) => match ($state) {
                    'verified' => 'Terverifikasi',
                    'assessed' => 'Dinilai (Rubrik)',
                    'revision_required' => 'Perlu Revisi',
                    'draft' => 'Draft',
                    default => ucfirst($state),
                })
                ->color(fn($state) => match ($state) {
                    'verified', 'assessed' => 'success',
                    'revision_required' => 'warning',
                    default => 'gray',
                }),
        ];
    }

    public function getTableActions(): array
    {
        return [
            Tables\Actions\Action::make('verify')
                ->label('Valid')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn(EmployeeKpiItem $record) => $record->formula_key_snapshot !== 'rubric' && !in_array($record->status, ['verified', 'assessed']))
                ->requiresConfirmation()
                ->action(function (EmployeeKpiItem $record) {
                    app(ReviewService::class)->verifyItem($record, 'valid', 'Diverifikasi via web', null, auth()->id());
                    Notification::make()->title("{$record->definition_code_snapshot} ditandai valid.")->success()->send();
                }),

            Tables\Actions\Action::make('revision')
                ->label('Minta Revisi')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn(EmployeeKpiItem $record) => !in_array($record->status, ['verified', 'assessed']))
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Alasan Revisi (Wajib)')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (EmployeeKpiItem $record, array $data) {
                    app(ReviewService::class)->verifyItem($record, 'revision_required', null, $data['reason'], auth()->id());
                    Notification::make()->title("{$record->definition_code_snapshot} diminta revisi.")->warning()->send();
                }),

            Tables\Actions\Action::make('rubric')
                ->label('Isi Rubrik')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('info')
                ->visible(fn(EmployeeKpiItem $record) => $record->formula_key_snapshot === 'rubric' && !in_array($record->status, ['verified', 'assessed']))
                ->form(fn(EmployeeKpiItem $record) => [
                    Forms\Components\CheckboxList::make('fulfilled')
                        ->label('Kriteria yang Terpenuhi')
                        ->helperText('Centang kriteria observasi yang terpenuhi. Skor dihitung otomatis dari poin.')
                        ->options(collect($record->rubric_snapshot['criteria'] ?? [])->pluck('criterion_text', 'id')->all())
                        ->columns(1),
                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan Supervisor (Opsional)')
                        ->rows(2),
                ])
                ->action(function (EmployeeKpiItem $record, array $data) {
                    $criteria = collect($record->rubric_snapshot['criteria'] ?? []);
                    $answers = $criteria->map(fn($c) => [
                        'criterion_id' => $c['id'],
                        'criterion_text' => $c['criterion_text'],
                        'points' => (float) $c['points'],
                        'is_fulfilled' => in_array($c['id'], $data['fulfilled'] ?? [], false),
                        'notes' => $data['notes'] ?? null,
                    ])->all();

                    app(ReviewService::class)->submitRubricAssessment($record, $answers, auth()->id());
                    Notification::make()->title("Rubrik {$record->definition_code_snapshot} disimpan.")->success()->send();
                }),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('requestRevision')
                ->label('Kirim Permintaan Revisi')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Kirim Permintaan Revisi ke Karyawan')
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Alasan Revisi (Wajib)')
                        ->helperText('Setidaknya satu indikator harus berstatus "Perlu Revisi".')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    try {
                        app(ReviewService::class)->requestRevision($this->record, $data['reason'], auth()->id());
                        Notification::make()->title('Permintaan revisi terkirim ke karyawan.')->success()->send();
                        $this->redirect(SupervisorReviewResource::getUrl('index'));
                    } catch (\Exception $e) {
                        Notification::make()->title('Gagal mengirim revisi')->body($e->getMessage())->danger()->send();
                    }
                }),

            Actions\Action::make('forward')
                ->label('Teruskan ke Manager')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Teruskan ke Manager?')
                ->modalDescription('Semua indikator harus sudah diverifikasi / dinilai. Skor akan dihitung ulang dan dikirim ke antrean approval Manager.')
                ->form([
                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan untuk Manager (Opsional)')
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    try {
                        app(ReviewService::class)->forwardToManager($this->record, $data['notes'] ?? null, auth()->id());
                        Notification::make()->title('KPI diteruskan ke antrean approval Manager.')->success()->send();
                        $this->redirect(SupervisorReviewResource::getUrl('index'));
                    } catch (\Exception $e) {
                        Notification::make()->title('Tidak dapat meneruskan')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    public function getTitle(): string
    {
        $emp = $this->record->employee?->name ?? 'Karyawan';

        return "Review KPI: {$emp}";
    }
}
