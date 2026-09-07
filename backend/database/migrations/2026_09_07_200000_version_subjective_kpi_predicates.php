<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kpi_rating_schemes') || ! Schema::hasColumn('kpi_rating_bands', 'manual_score')) {
            return;
        }

        DB::transaction(function (): void {
            $scheme = DB::table('kpi_rating_schemes')
                ->where('name', 'Skema Rating Standar 5-Tingkat')
                ->where('is_active', true)
                ->orderByDesc('version')
                ->first();
            if (! $scheme) {
                return;
            }

            $timestamp = now();
            $newSchemeId = DB::table('kpi_rating_schemes')->insertGetId([
                'name' => $scheme->name,
                'version' => DB::table('kpi_rating_schemes')->where('name', $scheme->name)->max('version') + 1,
                'score_cap' => $scheme->score_cap,
                'description' => 'Lima predikat untuk penilaian subjektif Supervisor.',
                'is_default' => $scheme->is_default,
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $scores = ['POOR' => 60, 'FAIR' => 75, 'GOOD' => 85, 'VERY_GOOD' => 95, 'STAR' => 100];
            foreach (DB::table('kpi_rating_bands')->where('rating_scheme_id', $scheme->id)->get() as $band) {
                DB::table('kpi_rating_bands')->insert([
                    'rating_scheme_id' => $newSchemeId,
                    'code' => $band->code,
                    'label' => $band->code === 'STAR' ? 'Istimewa' : $band->label,
                    'min_score' => $band->min_score,
                    'max_score' => $band->max_score,
                    'manual_score' => $scores[$band->code] ?? null,
                    'color' => $band->color,
                    'badge_icon' => $band->badge_icon,
                    'sort_order' => $band->sort_order,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }
            DB::table('kpi_rating_schemes')->where('name', $scheme->name)->where('id', '!=', $newSchemeId)->update([
                'is_active' => false,
                'is_default' => false,
                'updated_at' => $timestamp,
            ]);

            $sources = [
                'TEK-01' => 'system', 'TEK-02' => 'system', 'TEK-03' => 'cross_role', 'TEK-04' => 'system', 'TEK-07' => 'system',
                'CS-01' => 'system', 'CS-03' => 'system', 'CS-04' => 'system', 'CS-05' => 'cross_role',
                'ADM-01' => 'system', 'ADM-02' => 'system', 'ADM-03' => 'system', 'ADM-04' => 'system',
                'KSR-01' => 'import', 'KSR-02' => 'import', 'KSR-03' => 'import', 'KSR-04' => 'import',
                'GUD-01' => 'system', 'GUD-02' => 'system', 'GUD-03' => 'system', 'GUD-04' => 'system', 'GUD-05' => 'system',
                'TEK-05' => 'supervisor', 'TEK-06' => 'supervisor', 'CS-02' => 'supervisor',
                'ADM-06' => 'supervisor', 'KSR-05' => 'supervisor', 'GUD-06' => 'supervisor',
                'CS-06' => 'supervisor', 'ADM-05' => 'supervisor', 'KSR-06' => 'supervisor', 'GUD-07' => 'supervisor',
            ];
            $subjective = ['TEK-05', 'TEK-06', 'CS-02', 'ADM-06', 'KSR-05', 'GUD-06'];

            foreach ($sources as $code => $source) {
                $definition = ['source_type' => $source, 'updated_at' => $timestamp];
                if ($code === 'CS-02') {
                    $definition += ['metric_type' => 'rubric', 'default_formula' => 'rubric'];
                }
                DB::table('kpi_definitions')->where('code', $code)->update($definition);
            }

            $templates = DB::table('kpi_templates')->whereIn('code', [
                'TPL-TEK-01', 'TPL-CS-01', 'TPL-ADM-01', 'TPL-KSR-01', 'TPL-GUD-01',
            ])->get();
            foreach ($templates as $template) {
                $version = DB::table('kpi_template_versions')
                    ->where('kpi_template_id', $template->id)
                    ->where('status', 'active')
                    ->orderByDesc('version_number')
                    ->first();
                if (! $version) {
                    continue;
                }

                $newVersionId = DB::table('kpi_template_versions')->insertGetId([
                    'kpi_template_id' => $template->id,
                    'version_number' => DB::table('kpi_template_versions')->where('kpi_template_id', $template->id)->max('version_number') + 1,
                    'status' => 'active',
                    'total_weight' => $version->total_weight,
                    'rating_scheme_id' => $newSchemeId,
                    'checksum' => null,
                    'effective_from' => now()->startOfMonth()->addMonth()->toDateString(),
                    'effective_until' => null,
                    'activated_by' => null,
                    'activated_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $items = DB::table('kpi_template_items')
                    ->join('kpi_definitions', 'kpi_definitions.id', '=', 'kpi_template_items.kpi_definition_id')
                    ->where('template_version_id', $version->id)
                    ->select('kpi_template_items.*', 'kpi_definitions.code as definition_code')
                    ->get();
                foreach ($items as $item) {
                    $newItemId = DB::table('kpi_template_items')->insertGetId([
                        'template_version_id' => $newVersionId,
                        'kpi_definition_id' => $item->kpi_definition_id,
                        'weight' => $item->weight,
                        'target_value' => $item->target_value,
                        'target_unit' => $item->target_unit,
                        'target_json' => $item->target_json,
                        'formula_key' => in_array($item->definition_code, $subjective, true) ? 'rubric' : $item->formula_key,
                        'formula_params' => $item->formula_params,
                        'source_type' => $sources[$item->definition_code] ?? $item->source_type,
                        'evidence_required' => $item->evidence_required,
                        'is_mandatory' => $item->is_mandatory,
                        'sort_order' => $item->sort_order,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);

                    $rubric = DB::table('kpi_rubrics')->where('template_item_id', $item->id)->first();
                    if ($rubric) {
                        $newRubricId = DB::table('kpi_rubrics')->insertGetId([
                            'template_item_id' => $newItemId,
                            'name' => $rubric->name,
                            'description' => $rubric->description,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ]);
                        foreach (DB::table('kpi_rubric_criteria')->where('rubric_id', $rubric->id)->get() as $criterion) {
                            DB::table('kpi_rubric_criteria')->insert([
                                'rubric_id' => $newRubricId,
                                'criterion_text' => $criterion->criterion_text,
                                'points' => $criterion->points,
                                'is_mandatory' => $criterion->is_mandatory,
                                'sort_order' => $criterion->sort_order,
                                'created_at' => $timestamp,
                                'updated_at' => $timestamp,
                            ]);
                        }
                    } elseif ($item->definition_code === 'CS-02') {
                        $newRubricId = DB::table('kpi_rubrics')->insertGetId([
                            'template_item_id' => $newItemId,
                            'name' => 'Rubrik Kecepatan Melayani Pelanggan',
                            'description' => 'Panduan observasi; Supervisor tetap memilih satu predikat untuk keseluruhan KPI.',
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ]);
                        foreach ([
                            'Kebutuhan pelanggan dikenali tanpa penundaan yang tidak perlu',
                            'Proses dan perkiraan waktu layanan dijelaskan dengan jelas',
                            'Pelayanan diselesaikan sesuai antrean dan standar toko',
                        ] as $index => $criterion) {
                            DB::table('kpi_rubric_criteria')->insert([
                                'rubric_id' => $newRubricId,
                                'criterion_text' => $criterion,
                                'points' => 1,
                                'is_mandatory' => true,
                                'sort_order' => $index + 1,
                                'created_at' => $timestamp,
                                'updated_at' => $timestamp,
                            ]);
                        }
                    }
                }

                DB::table('kpi_template_versions')->where('id', $version->id)->update([
                    'status' => 'retired',
                    'effective_until' => now()->endOfMonth()->toDateString(),
                    'updated_at' => $timestamp,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Versi yang sudah dapat disnapshot periode tidak dihapus saat rollback.
    }
};
