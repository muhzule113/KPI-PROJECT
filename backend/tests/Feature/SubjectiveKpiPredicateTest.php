<?php

namespace Tests\Feature;

use App\Models\EmployeeKpiItem;
use App\Models\EmployeeKpi;
use App\Models\Branch;
use App\Models\KpiDailyEntry;
use App\Models\KpiPeriod;
use App\Models\KpiRatingScheme;
use App\Models\KpiTemplate;
use App\Models\User;
use App\Modules\Assessment\DailyAssessmentService;
use App\Modules\Configuration\KpiConfigurationService;
use App\Modules\Period\PeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectiveKpiPredicateTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_standard_period_snapshot_uses_official_subjective_and_attendance_inputs(): void
    {
        $items = EmployeeKpiItem::query()->get()->keyBy('definition_code_snapshot');

        foreach ([
            'TEK-01', 'TEK-02', 'TEK-03', 'TEK-04', 'TEK-07',
            'CS-01', 'CS-03', 'CS-04', 'CS-05',
            'ADM-01', 'ADM-02', 'ADM-03', 'ADM-04',
            'KSR-01', 'KSR-02', 'KSR-03', 'KSR-04',
            'GUD-01', 'GUD-02', 'GUD-03', 'GUD-04', 'GUD-05',
        ] as $code) {
            $this->assertTrue($items->get($code)?->isSystemSourced(), "{$code} harus memakai sumber resmi.");
            $this->assertFalse($items->get($code)?->isManualRated(), "{$code} tidak boleh menerima predikat manual.");
        }

        foreach (['TEK-05', 'TEK-06', 'CS-02', 'ADM-06', 'KSR-05', 'GUD-06'] as $code) {
            $item = $items->get($code);
            $this->assertTrue($item?->isManualRated(), "{$code} harus dinilai dengan predikat.");
            $this->assertEquals([
                'FAIR' => 75.0,
                'GOOD' => 85.0,
                'POOR' => 60.0,
                'STAR' => 100.0,
                'VERY_GOOD' => 95.0,
            ], collect($item?->manualRatingOptions())->pluck('score', 'code')->sortKeys()->all(), "{$code} harus menyediakan lima predikat.");
            $this->assertNotEmpty($item?->rubric_snapshot['criteria'] ?? [], "{$code} harus menampilkan panduan kriteria.");
        }

        foreach (['CS-06', 'ADM-05', 'KSR-06', 'GUD-07'] as $code) {
            $item = $items->get($code);
            $this->assertTrue($item?->isAttendanceIndicator(), "{$code} harus memakai status absensi.");
            $this->assertFalse($item?->isManualRated(), "{$code} tidak boleh memakai predikat.");
        }
    }

    public function test_subjective_rating_below_target_requires_a_note(): void
    {
        [$supervisor, $entries] = $this->prepareTechnicianEntriesForToday();
        $entry = $entries->first(fn (KpiDailyEntry $entry) => $entry->item->definition_code_snapshot === 'TEK-05');

        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/supervisor/daily/{$entry->id}/assess", [
                'decision' => 'approved',
                'actual_json' => ['rating_code' => 'GOOD'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Catatan wajib diisi jika predikat berada di bawah target indikator.');

        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/supervisor/daily/{$entry->id}/assess", [
                'decision' => 'approved',
                'actual_json' => ['rating_code' => 'GOOD'],
                'note' => 'SOP dokumentasi belum dijalankan konsisten.',
            ])
            ->assertOk()
            ->assertJsonPath('data.supervisor_score_percentage', 85);
    }

    public function test_objective_value_cannot_be_overridden_during_supervisor_confirmation(): void
    {
        [$supervisor, $entries] = $this->prepareTechnicianEntriesForToday();
        $entry = $entries->first(fn (KpiDailyEntry $entry) => $entry->item->definition_code_snapshot === 'TEK-01');

        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/supervisor/daily/{$entry->id}/assess", [
                'decision' => 'approved',
                'actual_decimal' => 99,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Nilai dari sumber resmi tidak boleh diubah melalui penilaian.');
    }

    public function test_data_migration_versions_standard_configuration_without_changing_existing_snapshots(): void
    {
        $template = KpiTemplate::where('code', 'TPL-TEK-01')->firstOrFail();
        $oldVersion = $template->activeVersion()->firstOrFail();
        $oldScheme = KpiRatingScheme::where('is_active', true)->firstOrFail();
        $existingKpi = EmployeeKpi::where('template_version_id', $oldVersion->id)->firstOrFail();
        $oldSnapshot = $existingKpi->items()->where('definition_code_snapshot', 'TEK-05')->value('rubric_snapshot');

        $migration = require database_path('migrations/2026_09_07_200000_version_subjective_kpi_predicates.php');
        $migration->up();

        $newVersion = $template->activeVersion()->firstOrFail();
        $this->assertNotSame($oldVersion->id, $newVersion->id);
        $this->assertSame('retired', $oldVersion->fresh()->status);
        $this->assertNotSame($oldScheme->id, $newVersion->rating_scheme_id);
        $this->assertSame($oldVersion->id, $existingKpi->fresh()->template_version_id);
        $this->assertEquals($oldSnapshot, $existingKpi->items()->where('definition_code_snapshot', 'TEK-05')->value('rubric_snapshot'));

        $csTemplate = KpiTemplate::where('code', 'TPL-CS-01')->firstOrFail()->activeVersion()->firstOrFail();
        $cs02 = $csTemplate->items()->whereHas('definition', fn ($query) => $query->where('code', 'CS-02'))->firstOrFail();
        $this->assertSame('supervisor', $cs02->source_type);
        $this->assertSame('rubric', $cs02->formula_key);
        $this->assertNotEmpty($cs02->rubric?->criteria()->get());
    }

    public function test_custom_predicate_is_snapshotted_only_into_the_next_period(): void
    {
        $superAdmin = User::role('super_admin')->firstOrFail();
        $configuration = app(KpiConfigurationService::class);
        $oldScheme = KpiRatingScheme::where('is_active', true)->firstOrFail();
        $configuration->copyRatingScheme($oldScheme, $superAdmin);
        $newScheme = KpiRatingScheme::where('name', $oldScheme->name)->where('is_active', false)->firstOrFail();
        $newScheme->bands()->where('code', 'GOOD')->update(['manual_score' => 88]);
        $configuration->activateRatingScheme($newScheme, $superAdmin);

        $template = KpiTemplate::where('code', 'TPL-TEK-01')->firstOrFail();
        $oldVersion = $template->activeVersion()->firstOrFail();
        $oldItem = EmployeeKpiItem::where('definition_code_snapshot', 'TEK-05')->firstOrFail();
        $this->actingAs($superAdmin, 'web');
        $configuration->copy($oldVersion);
        $newVersion = $template->versions()->where('status', 'draft')->firstOrFail();
        $newVersion->update(['rating_scheme_id' => $newScheme->id]);
        $configuration->activate($newVersion, $superAdmin);

        $start = now()->addMonth()->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $period = KpiPeriod::create([
            'name' => 'Periode Snapshot Predikat',
            'year' => $start->year,
            'month' => $start->month,
            'start_date' => $start,
            'end_date' => $end,
            'submission_deadline' => $end->copy()->subDays(2),
            'review_deadline' => $end->copy()->subDay(),
            'approval_deadline' => $end,
            'status' => 'DRAFT',
        ]);
        $period->branches()->attach(Branch::where('code', 'CAB-01')->firstOrFail());
        app(PeriodService::class)->markReady($period);

        $newItem = $period->employeeKpis()
            ->where('employee_id', $oldItem->employeeKpi->employee_id)
            ->firstOrFail()
            ->items()
            ->where('definition_code_snapshot', 'TEK-05')
            ->firstOrFail();

        $this->assertSame(85.0, (float) $oldItem->manualRating('GOOD')['score']);
        $this->assertSame(88.0, (float) $newItem->manualRating('GOOD')['score']);
        $this->assertSame($oldVersion->id, $oldItem->employeeKpi->template_version_id);
        $this->assertSame($newVersion->id, $newItem->employeeKpi->template_version_id);
    }

    private function prepareTechnicianEntriesForToday(): array
    {
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $period->update([
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'submission_deadline' => now()->addDay(),
            'review_deadline' => now()->addDays(2),
            'approval_deadline' => now()->addDays(3),
        ]);
        $employee = User::where('email', 'teknisi@toko.com')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        app(DailyAssessmentService::class)->employeeDay($employee, now()->toDateString());
        $entries = KpiDailyEntry::with('item')
            ->whereHas('item.employeeKpi.employee', fn ($query) => $query->where('user_id', $employee->id))
            ->whereDate('entry_date', now()->toDateString())
            ->get();

        return [$supervisor, $entries];
    }
}
