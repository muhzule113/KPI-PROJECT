<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\KpiPeriod;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_root_redirects_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_guest_is_redirected_to_the_existing_login_route(): void
    {
        $this->get('/app')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_open_the_inertia_dashboard(): void
    {
        $user = User::where('email', 'admin@kpi.com')->firstOrFail();

        $this->actingAs($user)
            ->get('/app')
            ->assertOk();
    }

    public function test_authenticated_user_receives_navigation_on_non_dashboard_pages(): void
    {
        $user = User::where('email', 'admin@kpi.com')->firstOrFail();

        $response = $this->actingAs($user)->get('/app/employees');

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/ResourceIndex')
            ->has('navigation'));

        $links = collect($response->inertiaProps('navigation'))
            ->flatMap(fn (array $group) => $group['items'] ?? [])
            ->pluck('href')
            ->all();

        $this->assertContains('/app/employees', $links);
        $this->assertContains('/app/kpi-periods', $links);
    }

    public function test_manager_can_download_kpi_csv_report(): void
    {
        $user = User::where('email', 'manager@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();

        $response = $this->actingAs($user)->get('/app/reports/kpi.csv');

        $response
            ->assertOk()
            ->assertDownload(sprintf('rekap-kpi-%04d-%02d.csv', $period->year, $period->month));
        $this->assertStringContainsString('Periode;', $response->streamedContent());
    }

    public function test_employee_cannot_download_kpi_csv_report(): void
    {
        $user = User::where('email', 'teknisi@toko.com')->firstOrFail();

        $this->actingAs($user)
            ->get('/app/reports/kpi.csv')
            ->assertForbidden();
    }

    public function test_manager_can_download_kpi_xlsx_report(): void
    {
        $user = User::where('email', 'manager@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();

        $this->actingAs($user)
            ->get('/app/reports/kpi.xlsx')
            ->assertOk()
            ->assertDownload(sprintf('rekap-kpi-%04d-%02d.xlsx', $period->year, $period->month));
    }

    public function test_manager_can_download_kpi_pdf_report(): void
    {
        $user = User::where('email', 'manager@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();

        $this->actingAs($user)
            ->get('/app/reports/kpi.pdf')
            ->assertOk()
            ->assertDownload(sprintf('rekap-kpi-%04d-%02d.pdf', $period->year, $period->month));
    }

    public function test_employee_cannot_download_kpi_xlsx_report(): void
    {
        $user = User::where('email', 'teknisi@toko.com')->firstOrFail();

        $this->actingAs($user)
            ->get('/app/reports/kpi.xlsx')
            ->assertForbidden();
    }

    public function test_sanctum_user_can_authenticate_kpi_realtime_channel(): void
    {
        $user = User::where('email', 'teknisi@toko.com')->firstOrFail();

        $this->actingAs($user, 'sanctum')
            ->postJson('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-kpi-updates',
            ])
            ->assertOk();
    }

    public function test_manager_can_filter_dashboard_by_period_and_position(): void
    {
        $user = User::where('email', 'manager@toko.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $position = Position::where('code', 'POS-TEK')->firstOrFail();

        $response = $this->actingAs($user)->get('/app?' . http_build_query([
            'period_id' => $period->id,
            'position_id' => $position->id,
        ]));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->has('filters.periods')
            ->has('filters.positions'));
        $this->assertSame($period->id, $response->inertiaProps('filters.period_id'));
        $this->assertSame($position->id, $response->inertiaProps('filters.position_id'));
    }
}
