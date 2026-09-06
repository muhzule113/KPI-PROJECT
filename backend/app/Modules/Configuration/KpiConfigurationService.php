<?php

namespace App\Modules\Configuration;

use App\Models\AuditEvent;
use App\Models\KpiRatingScheme;
use App\Models\KpiTemplateVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class KpiConfigurationService
{
    public function copyRatingScheme(KpiRatingScheme $scheme, User $user): string
    {
        DB::transaction(function () use ($scheme, $user): void {
            $scheme = KpiRatingScheme::with('bands')->lockForUpdate()->findOrFail($scheme->id);
            $copy = $scheme->replicate();
            $copy->version = KpiRatingScheme::where('name', $scheme->name)->max('version') + 1;
            $copy->is_active = false;
            $copy->save();
            foreach ($scheme->bands as $band) {
                $copy->bands()->create($band->only(['code', 'label', 'min_score', 'max_score', 'manual_score', 'color', 'badge_icon', 'sort_order']));
            }
            AuditEvent::log('rating_scheme_copied', 'KpiRatingScheme', (string) $copy->id, actorId: $user->id);
        });

        return 'Versi scheme rating baru dibuat beserta seluruh band.';
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

    public function activate(KpiTemplateVersion $version, User $user): string
    {
        return DB::transaction(function () use ($version, $user): string {
            $version->template()->lockForUpdate()->firstOrFail();
            $version = KpiTemplateVersion::lockForUpdate()->findOrFail($version->id);
            abort_unless($version->status === 'draft', 409, 'Hanya versi draft yang dapat diaktifkan.');
            $version->load('items.rubric.criteria');
            $issues = [];
            $weight = (float) $version->items->sum('weight');
            if ($version->items->isEmpty() || abs($weight - 100) > 0.001) {
                $issues[] = 'Total bobot indikator harus tepat 100%.';
            }
            if (! $version->ratingScheme()->whereHas('bands')->exists()) {
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
            if ($issues) {
                throw ValidationException::withMessages(['template' => implode(' ', array_unique($issues))]);
            }
            KpiTemplateVersion::where('kpi_template_id', $version->kpi_template_id)->where('status', 'active')->update(['status' => 'retired']);
            $version->update(['status' => 'active', 'total_weight' => $weight, 'activated_by' => $user->id, 'activated_at' => now()]);
            AuditEvent::log('template_activated', 'KpiTemplateVersion', (string) $version->id, actorId: $user->id);

            return 'Versi template aktif. Snapshot periode yang sudah berjalan tetap tersimpan.';
        });
    }

    public function copy(KpiTemplateVersion $version): string
    {
        return DB::transaction(function () use ($version): string {
            $template = $version->template()->lockForUpdate()->firstOrFail();
            $copy = $version->replicate(['activated_by', 'activated_at', 'checksum']);
            $copy->status = 'draft';
            $copy->version_number = (int) $template->versions()->max('version_number') + 1;
            $copy->save();
            foreach ($version->load('items.rubric.criteria')->items as $item) {
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
            AuditEvent::log('template_copied', 'KpiTemplateVersion', (string) $copy->id, actorId: auth()->id());

            return 'Versi draft '.$copy->version_number.' dibuat beserta target, bobot, dan rubrik.';
        });
    }
}
