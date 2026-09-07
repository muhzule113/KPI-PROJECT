<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\KpiPeriod;
use App\Models\Position;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
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

    public function test_logout_can_fetch_current_csrf_token_before_submitting(): void
    {
        $user = User::where('email', 'admin@kpi.com')->firstOrFail();

        $this->actingAs($user)
            ->withSession(['_token' => 'fresh-csrf-token'])
            ->getJson('/csrf-token')
            ->assertOk()
            ->assertJsonPath('token', 'fresh-csrf-token');

        $freshToken = 'fresh-csrf-token';
        $this->post('/logout', ['_token' => $freshToken])->assertRedirect('/login');
    }

    public function test_authenticated_user_can_open_the_inertia_dashboard(): void
    {
        $user = User::where('email', 'admin@kpi.com')->firstOrFail();

        $this->actingAs($user)
            ->get('/app')
            ->assertOk();
    }

    public function test_super_admin_receives_all_web_navigation_on_non_dashboard_pages(): void
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
        $this->assertContains('/app/service-tickets', $links);
        $this->assertContains('/app/team-tasks', $links);
        $this->assertNotContains('/app/supervisor-daily-assessments', $links);
        $this->assertContains('/app/manager-daily-assessments', $links);
        $this->assertContains('/app/kpi-periods', $links);
        $this->assertContains('/app/kpi-administration', $links);
        $this->assertNotContains('/app/kpi-definitions', $links);
    }

    public function test_super_admin_can_view_user_accounts_separately_from_employee_profiles(): void
    {
        $admin = User::where('email', 'admin@kpi.com')->firstOrFail();

        $response = $this->actingAs($admin)->get('/app/users');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/ResourceIndex')
            ->where('resource.key', 'users')
            ->where('pagination.total', User::count())
        );

        $emails = collect($response->inertiaProps('records'))
            ->pluck('values.email.value')
            ->all();

        $this->assertContains('manager@toko.com', $emails);
    }

    public function test_super_admin_can_open_role_specific_web_pages(): void
    {
        $admin = User::where('email', 'admin@kpi.com')->firstOrFail();
        $date = KpiPeriod::where('status', 'OPEN')->firstOrFail()->start_date->toDateString();

        foreach ([
            '/app/service-tickets',
            '/app/kpi-template-items',
            "/app/supervisor-attendance?date={$date}",
            "/app/supervisor-daily-assessments?date={$date}",
            "/app/manager-daily-assessments?date={$date}",
            "/app/my-kpi/daily?date={$date}",
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_super_admin_can_choose_a_branch_when_creating_stock_opname(): void
    {
        $admin = User::where('email', 'admin@kpi.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $branch = $period->branches()->firstOrFail();

        $form = $this->actingAs($admin)->get('/app/stock-opnames/create')->assertOk();
        $this->assertContains('branch_id', array_column($form->inertiaProps('resource.fields'), 'name'));

        $this->post('/app/stock-opnames', [
            'code' => 'OPN-SUPER-001',
            'period_id' => $period->id,
            'branch_id' => $branch->id,
            'deadline' => $period->end_date->toDateString(),
        ])->assertRedirect('/app/stock-opnames');

        $this->assertDatabaseHas('stock_opnames', ['code' => 'OPN-SUPER-001', 'branch_id' => $branch->id]);
    }

    public function test_manager_cannot_manage_user_accounts(): void
    {
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();

        $this->actingAs($manager)->get('/app/users')->assertForbidden();
    }

    public function test_super_admin_can_create_user_account_with_role(): void
    {
        $admin = User::where('email', 'admin@kpi.com')->firstOrFail();
        $employeeRole = Role::where('name', 'employee')->where('guard_name', 'web')->firstOrFail();

        $response = $this->actingAs($admin)->post('/app/users', [
            'name' => 'Akun Baru',
            'email' => 'akun.baru@toko.com',
            'password' => 'password-baru',
            'role_ids' => [$employeeRole->id],
            'is_active' => true,
        ]);

        $response->assertRedirect('/app/users');

        $user = User::where('email', 'akun.baru@toko.com')->firstOrFail();
        $this->assertSame('Akun Baru', $user->name);
        $this->assertTrue(Hash::check('password-baru', $user->password));
        $this->assertTrue($user->hasRole('employee'));
    }

    public function test_attendance_form_explains_period_and_status_rules(): void
    {
        $admin = User::where('email', 'manager@toko.com')->firstOrFail();

        $response = $this->actingAs($admin)->get('/app/attendances/create');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('resource.key', 'attendances')
            ->where('resource.can_create', true)
            ->where('form.values.attendance_date', '2026-08-01')
            ->where('resource.description', fn ($value) => str_contains($value, 'Supervisor mencatat timnya'))
        );
    }

    public function test_attendance_requires_check_in_for_worked_status(): void
    {
        $admin = User::where('email', 'manager@toko.com')->firstOrFail();
        $employee = Employee::where('email', 'gudang@toko.com')->firstOrFail();

        $this->actingAs($admin)
            ->post('/app/attendances', [
                'employee_id' => $employee->id,
                'attendance_date' => '2026-08-03',
                'status' => Attendance::STATUS_PRESENT,
            ])
            ->assertSessionHasErrors('check_in_time');

        $this->assertDatabaseMissing('attendances', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-03',
        ]);
    }

    public function test_attendance_excludes_excused_status_times_and_rejects_outside_period(): void
    {
        $admin = User::where('email', 'manager@toko.com')->firstOrFail();
        $employee = Employee::where('email', 'gudang@toko.com')->firstOrFail();

        $this->actingAs($admin)
            ->post('/app/attendances', [
                'employee_id' => $employee->id,
                'attendance_date' => '2026-08-04',
                'status' => Attendance::STATUS_PERMISSION,
                'check_in_time' => '08:00',
                'check_out_time' => '17:00',
                'note' => 'Izin disetujui',
            ])
            ->assertRedirect('/app/attendances');

        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-04',
            'status' => Attendance::STATUS_PERMISSION,
            'check_in_time' => null,
            'check_out_time' => null,
        ]);

        $this->actingAs($admin)
            ->post('/app/attendances', [
                'employee_id' => $employee->id,
                'attendance_date' => '2026-08-04',
                'status' => Attendance::STATUS_ABSENT,
                'note' => 'Duplikat',
            ])
            ->assertSessionHasErrors('employee_id');

        $this->actingAs($admin)
            ->post('/app/attendances', [
                'employee_id' => $employee->id,
                'attendance_date' => '2026-09-01',
                'status' => Attendance::STATUS_ABSENT,
                'note' => 'Di luar periode',
            ])
            ->assertSessionHasErrors('attendance_date');
    }

    public function test_non_dashboard_pages_receive_active_period_and_notifications(): void
    {
        $user = User::where('email', 'admin@kpi.com')->firstOrFail();
        $period = KpiPeriod::where('status', 'OPEN')->firstOrFail();
        $notification = SystemNotification::send(
            userId: $user->id,
            title: 'Tes notifikasi',
            body: 'Notifikasi harus tampil di seluruh halaman admin.',
        );

        $response = $this->actingAs($user)->get('/app/employees');

        $response->assertInertia(fn ($page) => $page
            ->where('activePeriod.id', $period->id)
            ->where('notifications.0.id', $notification->id));
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
        $user = User::where('email', 'kasir@toko.com')->firstOrFail();

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
        $user = User::where('email', 'kasir@toko.com')->firstOrFail();

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

        $response = $this->actingAs($user)->get('/app?'.http_build_query([
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
