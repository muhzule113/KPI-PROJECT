<?php

namespace App\Modules\Configuration;

use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\ImportMappingTemplate;
use App\Models\ImportMappingVersion;
use App\Models\KpiDefinition;
use App\Models\KpiRatingScheme;
use App\Models\KpiTemplate;
use App\Models\KpiTemplateItem;
use App\Models\KpiTemplateVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class KpiConfigurationService
{
    public function copyRatingScheme(KpiRatingScheme $scheme, User $user): string
    {
        $this->startRatingSchemeDraft($scheme, $user);

        return 'Versi scheme rating baru dibuat beserta seluruh band.';
    }

    public function startRatingSchemeDraft(KpiRatingScheme $scheme, User $user): KpiRatingScheme
    {
        return DB::transaction(function () use ($scheme, $user): KpiRatingScheme {
            $scheme = KpiRatingScheme::with('bands')->lockForUpdate()->findOrFail($scheme->id);
            $draft = KpiRatingScheme::where('name', $scheme->name)
                ->where('is_active', false)
                ->where('version', '>', $scheme->version)
                ->latest('version')
                ->first();
            if ($draft) {
                return $draft;
            }
            $copy = $scheme->replicate();
            $copy->version = KpiRatingScheme::where('name', $scheme->name)->max('version') + 1;
            $copy->is_active = false;
            $copy->save();
            foreach ($scheme->bands as $band) {
                $copy->bands()->create($band->only(['code', 'label', 'min_score', 'max_score', 'manual_score', 'color', 'badge_icon', 'sort_order']));
            }
            AuditEvent::log('rating_scheme_copied', 'KpiRatingScheme', (string) $copy->id, actorId: $user->id);

            return $copy;
        });
    }

    public function saveRatingScheme(KpiRatingScheme $scheme, array $bands, User $user): void
    {
        DB::transaction(function () use ($scheme, $bands, $user): void {
            $scheme = KpiRatingScheme::lockForUpdate()->findOrFail($scheme->id);
            abort_if($scheme->is_active, 409, 'Skema aktif tidak dapat diubah. Salin ke draft terlebih dahulu.');
            abort_unless(KpiRatingScheme::where('name', $scheme->name)->where('is_active', true)->where('version', '<', $scheme->version)->exists(), 409, 'Versi lama bukan draft wizard yang dapat diubah.');
            $existing = $scheme->bands()->get()->keyBy('id');
            abort_unless($existing->count() === count($bands), 422, 'Draft harus tetap memiliki lima predikat.');
            foreach ($bands as $band) {
                $record = $existing->get((int) $band['id']);
                abort_unless($record, 422, 'Predikat tidak ditemukan pada draft ini.');
                $record->update([
                    'label' => trim($band['label']),
                    'manual_score' => $band['manual_score'],
                    'min_score' => $band['min_score'],
                    'max_score' => $band['max_score'],
                    'color' => $band['color'],
                    'sort_order' => $band['sort_order'],
                ]);
            }
            AuditEvent::log('rating_scheme_draft_saved', 'KpiRatingScheme', (string) $scheme->id, actorId: $user->id);
        });
    }

    public function activateRatingScheme(KpiRatingScheme $scheme, User $user): string
    {
        return DB::transaction(function () use ($scheme, $user): string {
            $scheme = KpiRatingScheme::lockForUpdate()->findOrFail($scheme->id);
            abort_if($scheme->is_active, 409, 'Scheme rating ini sudah aktif.');
            if ($issues = $scheme->coverageIssues()) {
                throw ValidationException::withMessages(['bands' => implode(' ', $issues)]);
            }
            KpiRatingScheme::where('name', $scheme->name)->whereKeyNot($scheme->id)->update(['is_active' => false]);
            if ($scheme->is_default) {
                KpiRatingScheme::whereKeyNot($scheme->id)->update(['is_default' => false]);
            }
            $scheme->update(['is_active' => true]);
            AuditEvent::log('rating_scheme_activated', 'KpiRatingScheme', (string) $scheme->id, actorId: $user->id);

            return 'Scheme rating aktif. Snapshot periode lama tetap tidak berubah.';
        });
    }

    public function activateRatingSchemeForTemplates(KpiRatingScheme $scheme, array $templateIds, User $user): string
    {
        return DB::transaction(function () use ($scheme, $templateIds, $user): string {
            $scheme = KpiRatingScheme::with('bands')->lockForUpdate()->findOrFail($scheme->id);
            abort_if($scheme->is_active, 409, 'Skema predikat ini sudah aktif.');
            abort_unless(KpiRatingScheme::where('name', $scheme->name)->where('is_active', true)->where('version', '<', $scheme->version)->exists(), 409, 'Versi lama bukan draft wizard yang dapat diaktifkan.');
            if ($issues = $scheme->coverageIssues()) {
                throw ValidationException::withMessages(['bands' => implode(' ', $issues)]);
            }

            $templates = KpiTemplate::with(['position', 'activeVersion.items.rubric.criteria'])
                ->whereIn('id', $templateIds)
                ->lockForUpdate()
                ->get();
            abort_unless($templates->count() === count(array_unique($templateIds)), 422, 'Template yang dipilih tidak valid.');
            abort_if($templates->contains(fn (KpiTemplate $template) => $template->position?->code === 'POS-SPV'), 422, 'Template Supervisor hanya dapat diatur melalui Mode Lanjutan.');
            abort_if($templates->contains(fn (KpiTemplate $template) => ! $template->activeVersion), 422, 'Semua template harus memiliki versi aktif.');
            abort_if(KpiTemplateVersion::whereIn('kpi_template_id', $templateIds)->where('status', 'draft')->exists(), 409, 'Selesaikan atau hapus draft template yang ada sebelum mengubah predikat.');

            KpiRatingScheme::where('name', $scheme->name)->whereKeyNot($scheme->id)->update(['is_active' => false, 'is_default' => false]);
            if ($scheme->is_default) {
                KpiRatingScheme::whereKeyNot($scheme->id)->update(['is_default' => false]);
            }
            $scheme->update(['is_active' => true]);

            foreach ($templates as $template) {
                $draft = $this->replicateTemplateVersion($template->activeVersion, $scheme->id);
                $this->activateTemplateVersionLocked($draft, $user);
            }
            AuditEvent::log('rating_scheme_activated', 'KpiRatingScheme', (string) $scheme->id, after: ['template_ids' => $templateIds], actorId: $user->id);

            return 'Predikat dan versi template terpilih aktif untuk periode berikutnya. Snapshot periode lama tetap tidak berubah.';
        });
    }

    public function activate(KpiTemplateVersion $version, User $user): string
    {
        return DB::transaction(function () use ($version, $user): string {
            $version->template()->lockForUpdate()->firstOrFail();
            $version = KpiTemplateVersion::lockForUpdate()->findOrFail($version->id);
            abort_unless($version->status === 'draft', 409, 'Hanya versi draft yang dapat diaktifkan.');
            $issues = $this->templateIssues($version);
            if ($issues) {
                throw ValidationException::withMessages(['template' => implode(' ', array_unique($issues))]);
            }
            $this->activateTemplateVersionLocked($version, $user);

            return 'Versi template aktif. Snapshot periode yang sudah berjalan tetap tersimpan.';
        });
    }

    public function templateIssues(KpiTemplateVersion $version): array
    {
        $version->loadMissing('items.rubric.criteria', 'ratingScheme.bands');
        $issues = [];
        $weight = (float) $version->items->sum('weight');
        if ($version->items->isEmpty() || abs($weight - 100) > 0.001) {
            $issues[] = 'Total bobot indikator harus tepat 100%.';
        }
        if (! $version->ratingScheme || $version->ratingScheme->bands->isEmpty()) {
            $issues[] = 'Skema predikat harus memiliki batas penilaian.';
        } else {
            $issues = [...$issues, ...$version->ratingScheme->coverageIssues()];
        }
        foreach ($version->items as $item) {
            if (in_array($item->formula_key, ['higher_is_better', 'lower_is_better'], true) && (float) $item->target_value <= 0) {
                $issues[] = 'Target indikator harus lebih besar dari nol.';
            }
            if (in_array($item->formula_key, ['lower_is_better', 'zero_tolerance'], true)) {
                $base = $item->formula_key === 'zero_tolerance' ? ($item->target_json['full_score_limit'] ?? null) : $item->target_value;
                $failure = $item->target_json['failure_limit'] ?? null;
                if ($base === null || $failure === null || (float) $failure <= (float) $base) {
                    $issues[] = 'Batas gagal harus melebihi target atau batas skor penuh.';
                }
            }
            if ($item->formula_key === 'rubric' && (! $item->rubric || $item->rubric->criteria->isEmpty())) {
                $issues[] = 'Indikator rubrik harus memiliki kriteria penilaian.';
            }
        }

        return array_values(array_unique($issues));
    }

    public function copy(KpiTemplateVersion $version): string
    {
        $copy = DB::transaction(function () use ($version): KpiTemplateVersion {
            $template = $version->template()->lockForUpdate()->firstOrFail();
            $copy = $this->replicateTemplateVersion($version);
            AuditEvent::log('template_copied', 'KpiTemplateVersion', (string) $copy->id, actorId: auth()->id());

            return $copy;
        });

        return 'Versi draft '.$copy->version_number.' dibuat beserta target, bobot, dan rubrik.';
    }

    public function startTemplateDraft(KpiTemplateVersion $version, User $user): KpiTemplateVersion
    {
        return DB::transaction(function () use ($version, $user): KpiTemplateVersion {
            $version = KpiTemplateVersion::with('template.position')->lockForUpdate()->findOrFail($version->id);
            abort_if($version->template->position?->code === 'POS-SPV', 422, 'Template Supervisor hanya dapat diatur melalui Mode Lanjutan.');
            if ($version->status === 'draft') {
                return $version;
            }
            $draft = KpiTemplateVersion::where('kpi_template_id', $version->kpi_template_id)->where('status', 'draft')->latest('version_number')->first();
            if ($draft) {
                return $draft;
            }
            $draft = $this->replicateTemplateVersion($version);
            AuditEvent::log('template_copied', 'KpiTemplateVersion', (string) $draft->id, actorId: $user->id);

            return $draft;
        });
    }

    public function saveTemplateDraft(KpiTemplateVersion $draft, array $items, User $user): void
    {
        DB::transaction(function () use ($draft, $items, $user): void {
            $draft = KpiTemplateVersion::with('items.rubric.criteria')->lockForUpdate()->findOrFail($draft->id);
            abort_unless($draft->status === 'draft', 409, 'Hanya versi draft yang dapat diubah.');
            $definitions = KpiDefinition::whereIn('id', collect($items)->pluck('kpi_definition_id'))->get()->keyBy('id');
            abort_unless($definitions->count() === count(array_unique(array_column($items, 'kpi_definition_id'))), 422, 'Indikator tidak valid.');
            $kept = [];
            foreach ($items as $position => $payload) {
                $item = isset($payload['id'])
                    ? $draft->items->firstWhere('id', (int) $payload['id'])
                    : null;
                abort_if(isset($payload['id']) && ! $item, 422, 'Indikator bukan bagian dari draft ini.');
                $definition = $definitions->get((int) $payload['kpi_definition_id']);
                if (! $item) {
                    $item = new KpiTemplateItem([
                        'kpi_definition_id' => $definition->id,
                        'formula_key' => $definition->default_formula,
                        'source_type' => $definition->source_type,
                        'target_unit' => $definition->unit,
                        'evidence_required' => false,
                        'is_mandatory' => true,
                    ]);
                    $item->template_version_id = $draft->id;
                }
                $item->fill([
                    'kpi_definition_id' => $definition->id,
                    'target_value' => $payload['target_value'] ?? null,
                    'weight' => $payload['weight'],
                    'sort_order' => $payload['sort_order'] ?? $position + 1,
                ])->save();
                $kept[] = $item->id;

                if ($item->formula_key === 'rubric') {
                    $rubric = $item->rubric()->updateOrCreate([], [
                        'name' => trim($payload['rubric']['name'] ?? $definition->name),
                        'description' => trim($payload['rubric']['description'] ?? ''),
                    ]);
                    $criteriaIds = [];
                    foreach ($payload['rubric']['criteria'] ?? [] as $criterionPosition => $criterionPayload) {
                        $criterion = isset($criterionPayload['id']) ? $rubric->criteria()->find($criterionPayload['id']) : null;
                        abort_if(isset($criterionPayload['id']) && ! $criterion, 422, 'Kriteria bukan bagian dari rubrik ini.');
                        $criterion ??= $rubric->criteria()->make(['points' => 1, 'is_mandatory' => true]);
                        $criterion->fill([
                            'criterion_text' => trim($criterionPayload['criterion_text']),
                            'sort_order' => $criterionPayload['sort_order'] ?? $criterionPosition + 1,
                        ])->save();
                        $criteriaIds[] = $criterion->id;
                    }
                    $rubric->criteria()->whereNotIn('id', $criteriaIds)->delete();
                }
            }
            $draft->items()->whereNotIn('id', $kept)->delete();
            $draft->update(['total_weight' => $draft->items()->sum('weight')]);
            AuditEvent::log('template_draft_saved', 'KpiTemplateVersion', (string) $draft->id, actorId: $user->id);
        });
    }

    public function syncAssignments(array $assignments, User $user): void
    {
        DB::transaction(function () use ($assignments, $user): void {
            foreach ($assignments as $assignment) {
                Employee::whereKey($assignment['employee_id'])->lockForUpdate()->firstOrFail()->update(['supervisor_id' => $assignment['supervisor_id']]);
            }
            AuditEvent::log('kpi_assignments_saved', 'Employee', 'bulk', after: ['employee_ids' => array_column($assignments, 'employee_id')], actorId: $user->id);
        });
    }

    public function startImportMappingDraft(ImportMappingTemplate $template, array $defaults, User $user): ImportMappingVersion
    {
        return DB::transaction(function () use ($template, $defaults, $user): ImportMappingVersion {
            $template = ImportMappingTemplate::lockForUpdate()->findOrFail($template->id);
            $activeVersion = $template->versions()->where('is_active', true)->first();
            $draft = $template->versions()->where('is_active', false)
                ->when($activeVersion, fn ($query) => $query->where('version_number', '>', $activeVersion->version_number))
                ->latest('version_number')->first();
            if ($draft && ! ImportBatch::where('mapping_version_id', $draft->id)->exists()) {
                return $draft;
            }
            $source = $template->versions()->latest('version_number')->first();
            $draft = $template->versions()->create([
                'version_number' => (int) $template->versions()->max('version_number') + 1,
                'mappings_json' => $source?->mappings_json ?? $defaults,
                'is_active' => false,
            ]);
            AuditEvent::log('import_mapping_copied', 'ImportMappingVersion', (string) $draft->id, actorId: $user->id);

            return $draft;
        });
    }

    public function saveImportMappingDraft(ImportMappingVersion $version, array $mappings, User $user): void
    {
        DB::transaction(function () use ($version, $mappings, $user): void {
            $version = ImportMappingVersion::lockForUpdate()->findOrFail($version->id);
            abort_if($version->is_active || ImportBatch::where('mapping_version_id', $version->id)->exists(), 409, 'Versi aktif atau sudah digunakan tidak dapat diubah.');
            abort_if(ImportMappingVersion::where('mapping_template_id', $version->mapping_template_id)->where('is_active', true)->where('version_number', '>=', $version->version_number)->exists(), 409, 'Versi lama bukan draft wizard yang dapat diubah.');
            $version->update(['mappings_json' => $mappings]);
            AuditEvent::log('import_mapping_draft_saved', 'ImportMappingVersion', (string) $version->id, actorId: $user->id);
        });
    }

    public function activateImportMapping(ImportMappingVersion $version, User $user): string
    {
        return DB::transaction(function () use ($version, $user): string {
            $version = ImportMappingVersion::lockForUpdate()->findOrFail($version->id);
            abort_if($version->is_active, 409, 'Format import ini sudah aktif.');
            abort_if(ImportBatch::where('mapping_version_id', $version->id)->exists(), 409, 'Versi yang sudah digunakan tetap terkunci.');
            abort_if(ImportMappingVersion::where('mapping_template_id', $version->mapping_template_id)->where('is_active', true)->where('version_number', '>=', $version->version_number)->exists(), 409, 'Versi lama bukan draft wizard yang dapat diaktifkan.');
            ImportMappingVersion::where('mapping_template_id', $version->mapping_template_id)->whereKeyNot($version->id)->update(['is_active' => false]);
            $version->update(['is_active' => true]);
            AuditEvent::log('import_mapping_activated', 'ImportMappingVersion', (string) $version->id, actorId: $user->id);

            return 'Format import aktif untuk unggahan berikutnya.';
        });
    }

    private function replicateTemplateVersion(KpiTemplateVersion $version, ?int $ratingSchemeId = null): KpiTemplateVersion
    {
        $version->loadMissing('items.rubric.criteria');
        $copy = $version->replicate(['activated_by', 'activated_at', 'checksum']);
        $copy->status = 'draft';
        $copy->version_number = (int) KpiTemplateVersion::where('kpi_template_id', $version->kpi_template_id)->max('version_number') + 1;
        $copy->rating_scheme_id = $ratingSchemeId ?? $version->rating_scheme_id;
        $copy->effective_from = now()->startOfMonth()->addMonth();
        $copy->effective_until = null;
        $copy->save();
        foreach ($version->items as $item) {
            $newItem = $item->replicate();
            $newItem->template_version_id = $copy->id;
            $newItem->save();
            if ($item->rubric) {
                $rubric = $item->rubric->replicate();
                $rubric->template_item_id = $newItem->id;
                $rubric->save();
                foreach ($item->rubric->criteria as $criterion) {
                    $newCriterion = $criterion->replicate();
                    $newCriterion->rubric_id = $rubric->id;
                    $newCriterion->save();
                }
            }
        }

        return $copy;
    }

    private function activateTemplateVersionLocked(KpiTemplateVersion $version, User $user): void
    {
        $weight = (float) $version->items()->sum('weight');
        KpiTemplateVersion::where('kpi_template_id', $version->kpi_template_id)->where('status', 'active')->update([
            'status' => 'retired',
            'effective_until' => now()->endOfMonth(),
        ]);
        $version->update([
            'status' => 'active',
            'total_weight' => $weight,
            'effective_from' => $version->effective_from ?? now()->startOfMonth()->addMonth(),
            'activated_by' => $user->id,
            'activated_at' => now(),
        ]);
        AuditEvent::log('template_activated', 'KpiTemplateVersion', (string) $version->id, actorId: $user->id);
    }
}
