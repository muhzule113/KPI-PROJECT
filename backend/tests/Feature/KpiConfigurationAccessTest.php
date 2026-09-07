<?php

namespace Tests\Feature;

use App\Models\AdminWorkLog;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\EmployeeKpi;
use App\Models\ImportMappingTemplate;
use App\Models\ImportMappingVersion;
use App\Models\KpiPeriod;
use App\Models\KpiRatingScheme;
use App\Models\KpiTemplateVersion;
use App\Models\User;
use App\Modules\Import\CashierImportService;
use App\Support\KpiConfigurationResources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KpiConfigurationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_super_admin_can_configure_draft_templates_but_cannot_mutate_active_versions(): void
    {
        $admin = User::role('super_admin')->firstOrFail();
        $this->actingAs($admin, 'web');
        foreach (array_keys(KpiConfigurationResources::definitions()) as $resource) {
            $this->get('/app/'.$resource)->assertOk();
        }
        $version = KpiTemplateVersion::where('status', 'active')->firstOrFail();
        $snapshotCount = EmployeeKpi::where('template_version_id', $version->id)->count();
        $this->get('/app/kpi-template-versions/'.$version->id.'/edit')->assertForbidden();
        $this->post('/app/kpi-template-versions/'.$version->id.'/actions/copy')->assertSessionHas('success');
        $draft = KpiTemplateVersion::where('kpi_template_id', $version->kpi_template_id)->where('status', 'draft')->firstOrFail();
        $this->assertSame($version->items()->count(), $draft->items()->count());
        $item = $draft->items()->firstOrFail();
        $weight = $item->weight;
        $item->update(['weight' => 0]);
        $this->post('/app/kpi-template-versions/'.$draft->id.'/actions/activate')->assertSessionHas('error');
        $this->assertSame('draft', $draft->fresh()->status);
        $item->update(['weight' => $weight]);
        $this->post('/app/kpi-template-versions/'.$draft->id.'/actions/activate')->assertSessionHas('success');
        $this->assertSame('active', $draft->fresh()->status);
        $this->assertSame('retired', $version->fresh()->status);
        $this->assertSame($snapshotCount, EmployeeKpi::where('template_version_id', $version->id)->count());
        $this->actingAs(User::role('super_admin')->firstOrFail(), 'web')->get('/app/kpi-template-items')->assertOk();
    }

    public function test_mapping_config_is_applied_to_cashier_upload_and_used_version_is_immutable(): void
    {
        Storage::fake('local');
        $admin = User::role('kpi_admin')->firstOrFail();
        $template = ImportMappingTemplate::create(['name' => 'Format Cabang', 'source_application' => 'TEST_POS', 'is_active' => true]);
        $payload = ['mapping_template_id' => $template->id, 'version_number' => 1, 'is_active' => true];
        $headers = [];
        foreach (array_keys(KpiConfigurationResources::IMPORT_COLUMNS) as $index => $column) {
            $payload['column_'.$column] = 'Header Khusus '.$index;
            $headers[] = $payload['column_'.$column];
        }
        $this->actingAs($admin, 'web')->post('/app/import-mapping-versions', $payload)->assertRedirect('/app/import-mapping-versions');
        $mapping = ImportMappingVersion::where('mapping_template_id', $template->id)->firstOrFail();
        $cashier = User::where('email', 'kasir@toko.com')->firstOrFail();
        $period = KpiPeriod::active();
        $csv = implode(',', $headers)."\n".implode(',', ['MAP-001', $period->start_date->toDateString(), $cashier->employee->name, 10000, 10000, 10000, 100, 'SUCCESS'])."\n";
        $batch = app(CashierImportService::class)->uploadAndStage(UploadedFile::fake()->createWithContent('mapping.csv', $csv), $period, uploaderId: $cashier->id);
        $this->assertSame($mapping->id, $batch->mapping_version_id);
        $this->assertSame('ready_for_preview', $batch->status, json_encode($batch->issues_json));
        $this->assertSame(1, $batch->valid_rows);
        $this->put('/app/import-mapping-versions/'.$mapping->id, $payload)->assertForbidden();
    }

    public function test_work_logs_use_authenticated_employee_and_hide_other_owners(): void
    {
        $admin = User::where('email', 'admin_staff@toko.com')->firstOrFail();
        $other = User::where('email', 'kasir@toko.com')->firstOrFail();
        $period = KpiPeriod::active();
        $foreign = AdminWorkLog::create(['employee_id' => $other->employee->id, 'period_id' => $period->id, 'work_date' => $period->start_date, 'records_input' => 1, 'records_corrected' => 0, 'documents_eligible' => 1, 'documents_complete' => 1, 'reconciliations_total' => 1, 'reconciliations_success' => 1, 'recorded_by' => $other->id]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/resources/admin-work-logs/'.$foreign->id)->assertNotFound();
        $this->postJson('/api/v1/resources/admin-work-logs', ['employee_id' => $other->employee->id, 'period_id' => 9999, 'work_date' => $period->start_date->toDateString(), 'records_input' => 2, 'records_corrected' => 0, 'documents_eligible' => 2, 'documents_complete' => 2, 'reconciliations_total' => 2, 'reconciliations_success' => 2, 'recorded_by' => $other->id])->assertCreated();
        $this->assertDatabaseHas('admin_work_logs', ['employee_id' => $admin->employee->id, 'period_id' => $period->id, 'recorded_by' => $admin->id, 'records_input' => 2]);
        $period->update(['status' => 'PUBLISHED']);
        $this->getJson('/api/v1/resources/admin-work-logs')->assertOk()->assertJsonPath('data.pagination.total', 1);
    }

    public function test_admin_kpi_retries_daily_preparation_and_system_audit_stays_technical(): void
    {
        $period = KpiPeriod::active();
        $admin = User::role('kpi_admin')->firstOrFail();
        $this->actingAs($admin, 'web')->post('/app/kpi-periods/'.$period->id.'/actions/sync_daily', ['through_date' => $period->start_date->toDateString()])->assertSessionHas('success');
        $sync = AuditEvent::where('action', 'daily_kpi_sync_completed')->latest()->firstOrFail();
        $technical = AuditEvent::log('web_login', 'User', (string) $admin->id);
        $business = AuditEvent::log('kpi_approved', 'EmployeeKpi', '1', after: ['final_score' => 80]);
        $systemRows = $this->actingAs(User::role('super_admin')->firstOrFail(), 'web')->get('/app/audit-events?per_page=50')->assertOk()->inertiaProps('records');
        $systemIds = array_column($systemRows, 'id');
        $this->assertContains((string) $technical->id, $systemIds);
        $this->assertContains((string) $sync->id, $systemIds);
        $this->assertContains((string) $business->id, $systemIds);
        $kpiRows = $this->actingAs($admin, 'web')->get('/app/audit-events?per_page=50')->assertOk()->inertiaProps('records');
        $this->assertContains((string) $sync->id, array_column($kpiRows, 'id'));
    }

    public function test_transferred_supervisor_cannot_list_previous_branch_review_queue(): void
    {
        $supervisor = User::role('supervisor')->firstOrFail();
        $kpi = EmployeeKpi::where('supervisor_id_snapshot', $supervisor->employee->id)->firstOrFail();
        $kpi->update(['status' => 'submitted']);
        $this->actingAs($supervisor, 'web')->get('/app/supervisor-reviews')->assertOk()->assertInertia(fn ($page) => $page->where('pagination.total', 1));
        $newBranch = Branch::create(['code' => 'TRANSFER', 'name' => 'Cabang Baru', 'is_active' => true]);
        $supervisor->employee->update(['branch_id' => $newBranch->id]);
        $this->get('/app/supervisor-reviews')->assertOk()->assertInertia(fn ($page) => $page->where('pagination.total', 0));
    }

    public function test_active_rating_scheme_is_replaced_through_a_validated_version(): void
    {
        $admin = User::role('super_admin')->firstOrFail();
        $scheme = KpiRatingScheme::where('is_active', true)->firstOrFail();
        $this->actingAs($admin, 'web')->get("/app/kpi-rating-schemes/{$scheme->id}/edit")->assertForbidden();
        $this->post("/app/kpi-rating-schemes/{$scheme->id}/actions/copy")->assertSessionHas('success');
        $draft = KpiRatingScheme::where('name', $scheme->name)->where('is_active', false)->firstOrFail();
        $this->assertSame($scheme->bands()->count(), $draft->bands()->count());

        $lowest = $draft->bands()->reorder('min_score')->firstOrFail();
        $lowest->update(['min_score' => 1]);
        $this->post("/app/kpi-rating-schemes/{$draft->id}/actions/activate")->assertSessionHas('error');
        $lowest->update(['min_score' => 0]);
        $this->post("/app/kpi-rating-schemes/{$draft->id}/actions/activate")->assertSessionHas('success');
        $this->assertTrue($draft->fresh()->is_active);
        $this->assertFalse($scheme->fresh()->is_active);
    }

    public function test_kpi_admin_cannot_manage_catalog_but_keeps_operational_configuration(): void
    {
        $admin = User::role('kpi_admin')->firstOrFail();

        foreach ([
            'kpi-definitions', 'kpi-rating-schemes', 'kpi-rating-bands', 'kpi-templates',
            'kpi-template-versions', 'kpi-template-items', 'kpi-rubrics', 'kpi-rubric-criteria',
        ] as $resource) {
            $this->actingAs($admin, 'web')->get('/app/'.$resource)->assertForbidden();
        }

        foreach (['kpi-periods', 'employee-kpis', 'kpi-assignments', 'import-mapping-templates', 'import-mapping-versions'] as $resource) {
            $this->actingAs($admin, 'web')->get('/app/'.$resource)->assertOk();
        }
    }

    public function test_rating_scheme_activation_requires_five_unique_valid_manual_predicates(): void
    {
        $admin = User::role('super_admin')->firstOrFail();
        $scheme = KpiRatingScheme::where('is_active', true)->firstOrFail();
        $this->actingAs($admin, 'web')
            ->post("/app/kpi-rating-schemes/{$scheme->id}/actions/copy")
            ->assertSessionHas('success');
        $draft = KpiRatingScheme::where('name', $scheme->name)->where('is_active', false)->firstOrFail();
        $poor = $draft->bands()->where('code', 'POOR')->firstOrFail();

        $poor->update(['manual_score' => null]);
        $this->post("/app/kpi-rating-schemes/{$draft->id}/actions/activate")->assertSessionHas('error');
        $this->assertFalse($draft->fresh()->is_active);

        $poor->update(['manual_score' => 60, 'code' => 'FAIR']);
        $this->post("/app/kpi-rating-schemes/{$draft->id}/actions/activate")->assertSessionHas('error');
        $this->assertFalse($draft->fresh()->is_active);

        $poor->update(['manual_score' => 101, 'code' => 'POOR']);
        $this->post("/app/kpi-rating-schemes/{$draft->id}/actions/activate")->assertSessionHas('error');
        $this->assertFalse($draft->fresh()->is_active);
    }
}
