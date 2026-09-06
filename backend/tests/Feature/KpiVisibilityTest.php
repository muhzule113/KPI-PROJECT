<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\KpiDailyEntry;
use App\Models\KpiEvidence;
use App\Models\KpiPeriod;
use App\Models\SystemNotification;
use App\Models\User;
use Database\Seeders\WorkflowDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class KpiVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_demo_cycle_uses_operational_facts_and_publishes_all_roles_once(): void
    {
        $this->seed(WorkflowDemoSeeder::class);
        $period = KpiPeriod::where('name', 'like', 'Demo siklus lengkap%')->firstOrFail();
        $this->assertSame('LOCKED', $period->status);
        $this->assertNotNull($period->published_at);
        $this->assertSame(6, $period->employeeKpis()->where('status', 'locked')->count());
        $this->assertDatabaseHas('service_tickets', ['period_id' => $period->id, 'customer_name' => 'Pelanggan Demo Siklus', 'status' => 'delivered']);
        $this->assertSame(1.0, (float) $period->employeeKpis()->where('position_code_snapshot', 'POS-TEK')->firstOrFail()->items()->firstOrFail()->actual_decimal);
        $count = KpiDailyEntry::count();
        $this->seed(WorkflowDemoSeeder::class);
        $this->assertSame($count, KpiDailyEntry::count());
    }

    public function test_own_scores_are_hidden_everywhere_until_publication_and_history_remains_readable(): void
    {
        $user = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $kpi = $user->employee->kpis()->with('period')->firstOrFail();
        $kpi->update(['status' => 'approved', 'final_score' => 94.75, 'rating_code' => 'VERY_GOOD', 'rating_label' => 'Sangat Baik']);
        $item = $kpi->items()->where('source_type_snapshot', 'supervisor')->firstOrFail();
        $item->update(['actual_decimal' => 95, 'actual_json' => ['rating_code' => 'VERY_GOOD'], 'achievement_percentage' => 99.5, 'weighted_score' => 12.34]);
        SystemNotification::create(['user_id' => $user->id, 'title' => 'Skor 94.75', 'body' => 'Sangat Baik: 94.75', 'type' => 'success', 'entity_type' => 'EmployeeKpi', 'entity_id' => $kpi->id]);

        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/v1/my-kpi/active')->assertOk()->assertJsonPath('data.final_score', null)->assertJsonPath('data.rating_label', null)->assertJsonPath('data.score_visible', false);
        $this->getJson('/api/v1/my-kpi/items/'.$item->id)->assertOk()->assertJsonPath('data.actual_decimal', null)->assertJsonPath('data.actual_json', null)->assertJsonPath('data.weighted_score', null)->assertJsonPath('data.achievement_percentage', null);
        $this->getJson('/api/v1/my-kpi/history')->assertOk()->assertJsonPath('data.0.final_score', null);
        $dashboard = $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.my_kpi.final_score', null)->assertJsonPath('data.metrics.average', null)->assertJsonPath('data.top_performers', []);
        $this->assertStringNotContainsString('94.75', $dashboard->getContent());
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonMissing(['title' => 'Skor 94.75'])->assertJsonPath('data.0.destination.screen', 'my-kpi');
        $this->getJson('/api/v1/reports/kpi/export/csv')->assertForbidden();

        $kpi->period->update(['published_at' => now(), 'status' => 'LOCKED', 'locked_at' => now()]);
        $this->getJson('/api/v1/my-kpi/active?period_id='.$kpi->period_id)->assertOk()->assertJsonPath('data.final_score', 94.75)->assertJsonPath('data.score_visible', true);
        $this->getJson('/api/v1/my-kpi/history')->assertOk()->assertJsonPath('data.0.final_score', 94.75);
    }

    public function test_manager_reports_dashboard_exports_and_notifications_use_snapshot_branch_and_assignment(): void
    {
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail()->employee;
        $kpi = $employee->kpis()->firstOrFail();
        $kpi->update(['branch_id_snapshot' => Branch::where('code', 'CAB-02')->value('id'), 'final_score' => 92.12]);
        SystemNotification::create(['user_id' => $manager->id, 'title' => 'Rahasia cabang lain', 'body' => 'Tindak lanjut', 'type' => 'info', 'entity_type' => 'EmployeeKpi', 'entity_id' => $kpi->id]);

        $this->actingAs($manager, 'sanctum');
        $report = $this->getJson('/api/v1/reports/kpi')->assertOk();
        $this->assertNotContains($kpi->id, array_column($report->json('data.rows'), 'kpi_id'));
        $this->assertStringNotContainsString('92.12', $this->getJson('/api/v1/dashboard')->assertOk()->getContent());
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonMissing(['title' => 'Rahasia cabang lain']);
        $csv = $this->get('/api/v1/reports/kpi/export/csv')->assertOk()->streamedContent();
        $this->assertStringNotContainsString($employee->name, $csv);

        $this->actingAs($manager);
        $csv = $this->get('/app/reports/kpi.csv')->assertOk()->streamedContent();
        $this->assertStringNotContainsString($employee->name, $csv);
        $auditor = User::where('email', 'auditor@kpi.com')->firstOrFail();
        $this->actingAs($auditor);
        $csv = $this->get('/app/reports/kpi.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString($employee->name, $csv);
    }

    public function test_supervisor_cannot_learn_own_unpublished_score_from_team_export(): void
    {
        $user = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $kpi = $user->employee->kpis()->firstOrFail();
        $kpi->update(['final_score' => 91.23, 'rating_label' => 'PREDIKAT-RAHASIA']);
        $this->actingAs($user, 'sanctum');
        $report = $this->getJson('/api/v1/reports/kpi')->assertOk();
        $row = collect($report->json('data.rows'))->firstWhere('kpi_id', $kpi->id);
        $this->assertNotNull($row);
        $this->assertNull($row['final_score']);
        $this->assertNull($row['rating_label']);
        $this->assertStringNotContainsString('PREDIKAT-RAHASIA', $this->get('/api/v1/reports/kpi/export/csv')->assertOk()->streamedContent());
    }

    public function test_evidence_scope_follows_historical_snapshot_not_current_employee_branch(): void
    {
        Storage::fake('local');
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail()->employee;
        $kpi = $employee->kpis()->firstOrFail();
        $evidence = KpiEvidence::create(['employee_kpi_item_id' => $kpi->items()->firstOrFail()->id, 'uploaded_by' => $employee->user_id, 'file_name' => 'bukti.pdf', 'file_path' => 'kpi/bukti.pdf', 'mime_type' => 'application/pdf', 'file_size' => 4, 'scan_status' => 'clean', 'sha256_hash' => hash('sha256', 'demo')]);
        Storage::disk('local')->put($evidence->file_path, 'demo');
        $employee->update(['branch_id' => Branch::where('code', 'CAB-02')->value('id')]);
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();
        $url = URL::temporarySignedRoute('app.kpi.evidence.download', now()->addMinutes(5), ['evidenceId' => $evidence->id]);
        $this->actingAs($manager)->get($url)->assertOk();
        $this->actingAs(User::where('email', 'admin@kpi.com')->firstOrFail())->get($url)->assertForbidden();
        $kpi->update(['manager_id_snapshot' => null]);
        $this->actingAs($manager)->get($url)->assertForbidden();
        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
    }
}
