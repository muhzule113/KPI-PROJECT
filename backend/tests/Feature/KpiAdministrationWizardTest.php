<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\ImportBatch;
use App\Models\ImportMappingTemplate;
use App\Models\KpiPeriod;
use App\Models\KpiRatingScheme;
use App\Models\KpiTemplate;
use App\Models\KpiTemplateVersion;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\KpiConfigurationResources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KpiAdministrationWizardTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_access_and_sidebar_follow_the_two_administration_roles(): void
    {
        $superAdmin = User::role('super_admin')->firstOrFail();
        $kpiAdmin = User::role('kpi_admin')->firstOrFail();
        $manager = User::role('owner_manager')->firstOrFail();

        $this->actingAs($superAdmin)->get('/app/kpi-administration')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/KpiAdministration')
            ->where('access.catalog', true)
            ->where('access.assignments', true)
            ->where('access.imports', true));
        $this->actingAs($kpiAdmin)->get('/app/kpi-administration')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('access.catalog', false)
            ->where('access.assignments', true)
            ->where('access.imports', true));
        $this->actingAs($manager)->get('/app/kpi-administration')->assertForbidden();

        $items = collect(AdminNavigation::for($superAdmin))->flatMap(fn ($group) => $group['items']);
        $this->assertSame(1, $items->where('href', '/app/kpi-administration')->count());
        $this->assertFalse($items->contains(fn ($item) => in_array($item['href'], ['/app/kpi-definitions', '/app/kpi-assignments', '/app/import-mapping-versions'], true)));
    }

    public function test_predicate_activation_versions_selected_staff_template_without_touching_snapshots(): void
    {
        $admin = User::role('super_admin')->firstOrFail();
        $scheme = KpiRatingScheme::where('is_active', true)->latest('version')->firstOrFail();
        $template = KpiTemplate::whereHas('position', fn ($query) => $query->where('code', '!=', 'POS-SPV'))->whereHas('activeVersion')->firstOrFail();
        $oldVersion = $template->activeVersion()->firstOrFail();
        $snapshotCount = EmployeeKpi::where('template_version_id', $oldVersion->id)->count();

        $this->actingAs($admin)->post('/app/kpi-administration/predicates/start', ['scheme_id' => $scheme->id])->assertRedirect();
        $draft = KpiRatingScheme::where('name', $scheme->name)->where('version', '>', $scheme->version)->firstOrFail();
        $bands = $draft->bands()->get()->map(fn ($band) => [
            'id' => $band->id,
            'label' => $band->label,
            'manual_score' => (float) $band->manual_score,
            'min_score' => (float) $band->min_score,
            'max_score' => (float) $band->max_score,
            'color' => $band->color,
            'sort_order' => $band->sort_order,
        ])->all();
        $invalid = $bands;
        $invalid[0]['manual_score'] = 101;
        $this->put("/app/kpi-administration/predicates/{$draft->id}", ['bands' => $invalid])->assertSessionHasErrors('bands.0.manual_score');
        $this->put("/app/kpi-administration/predicates/{$draft->id}", ['bands' => $bands])->assertSessionHasNoErrors();
        $this->post("/app/kpi-administration/predicates/{$draft->id}/activate", ['template_ids' => [$template->id]])->assertSessionHas('success');

        $newVersion = KpiTemplateVersion::where('kpi_template_id', $template->id)->where('status', 'active')->firstOrFail();
        $this->assertTrue($draft->fresh()->is_active);
        $this->assertSame($draft->id, $newVersion->rating_scheme_id);
        $this->assertSame('retired', $oldVersion->fresh()->status);
        $this->assertSame($snapshotCount, EmployeeKpi::where('template_version_id', $oldVersion->id)->count());
    }

    public function test_template_draft_saves_nested_items_but_invalid_weight_cannot_activate(): void
    {
        $admin = User::role('super_admin')->firstOrFail();
        $active = KpiTemplateVersion::where('status', 'active')
            ->whereHas('template.position', fn ($query) => $query->where('code', '!=', 'POS-SPV'))
            ->firstOrFail();
        $this->actingAs($admin)->post("/app/kpi-administration/templates/{$active->id}/start")->assertRedirect();
        $draft = KpiTemplateVersion::where('kpi_template_id', $active->kpi_template_id)->where('status', 'draft')->firstOrFail();
        $items = $this->templateItems($draft);
        $items[0]['weight'] = 0;

        $this->put("/app/kpi-administration/templates/{$draft->id}", ['items' => $items])->assertSessionHasNoErrors();
        $this->post("/app/kpi-administration/templates/{$draft->id}/activate")->assertSessionHasErrors('template');
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_assignment_checks_role_branch_active_account_and_self_review(): void
    {
        $admin = User::role('kpi_admin')->firstOrFail();
        $employee = Employee::with(['position', 'supervisor'])->whereNotNull('supervisor_id')->whereHas('position', fn ($query) => $query->where('code', '!=', 'POS-SPV'))->firstOrFail();

        $this->actingAs($admin)->put('/app/kpi-administration/assignments', ['assignments' => [[
            'employee_id' => $employee->id,
            'supervisor_id' => $employee->id,
        ]]])->assertSessionHasErrors('assignments.0.supervisor_id');

        $this->put('/app/kpi-administration/assignments', ['assignments' => [[
            'employee_id' => $employee->id,
            'supervisor_id' => $employee->supervisor_id,
        ]]])->assertSessionHasNoErrors()->assertSessionHas('success');
    }

    public function test_mapping_rejects_duplicate_headers_and_used_versions_stay_locked(): void
    {
        $admin = User::role('kpi_admin')->firstOrFail();
        $template = ImportMappingTemplate::create(['name' => 'Wizard POS', 'source_application' => 'WIZARD_POS', 'is_active' => true]);
        $this->actingAs($admin)->post("/app/kpi-administration/imports/{$template->id}/start")->assertRedirect();
        $version = $template->versions()->firstOrFail();
        $duplicates = array_fill_keys(array_keys(KpiConfigurationResources::IMPORT_COLUMNS), 'Header sama');
        $this->put("/app/kpi-administration/imports/{$version->id}", ['mappings' => $duplicates])->assertSessionHasErrors('mappings');

        $valid = array_combine(array_keys(KpiConfigurationResources::IMPORT_COLUMNS), array_map(fn ($key) => 'Kolom '.$key, array_keys(KpiConfigurationResources::IMPORT_COLUMNS)));
        $this->put("/app/kpi-administration/imports/{$version->id}", ['mappings' => $valid])->assertSessionHasNoErrors();
        $this->post("/app/kpi-administration/imports/{$version->id}/activate")->assertSessionHas('success');
        ImportBatch::create([
            'file_name' => 'used.csv', 'file_path' => 'imports/used.csv', 'file_hash_sha256' => hash('sha256', 'used'),
            'mapping_version_id' => $version->id, 'period_id' => KpiPeriod::active()->id, 'uploader_id' => $admin->id,
        ]);
        $version->update(['is_active' => false]);
        $this->put("/app/kpi-administration/imports/{$version->id}", ['mappings' => $valid])->assertConflict();
    }

    private function templateItems(KpiTemplateVersion $version): array
    {
        return $version->load('items.rubric.criteria')->items->map(function ($item): array {
            $payload = [
                'id' => $item->id,
                'kpi_definition_id' => $item->kpi_definition_id,
                'target_value' => $item->target_value,
                'weight' => $item->weight,
                'sort_order' => $item->sort_order,
            ];
            if ($item->rubric) {
                $payload['rubric'] = [
                    'name' => $item->rubric->name,
                    'description' => $item->rubric->description,
                    'criteria' => $item->rubric->criteria->map(fn ($criterion) => [
                        'id' => $criterion->id,
                        'criterion_text' => $criterion->criterion_text,
                        'sort_order' => $criterion->sort_order,
                    ])->all(),
                ];
            }

            return $payload;
        })->all();
    }
}
