<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Support\CapabilityMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function accessMatrix(): array
    {
        return [
            'Teknisi' => ['employee', 'POS-TEK', ['mobile']],
            'Pelayan' => ['employee', 'POS-CS', ['mobile']],
            'Gudang' => ['employee', 'POS-GUD', ['mobile']],
            'Kasir' => ['employee', 'POS-KSR', ['mobile', 'web']],
            'Admin Operasional' => ['employee', 'POS-ADM', ['mobile', 'web']],
            'Supervisor' => ['supervisor', 'POS-SPV', ['mobile', 'web']],
            'Manager' => ['owner_manager', 'POS-OWN', ['mobile', 'web']],
            'Admin KPI' => ['kpi_admin', null, ['web']],
            'Super Admin' => ['super_admin', null, ['web']],
            'Auditor' => ['auditor', null, ['web']],
        ];
    }

    #[DataProvider('accessMatrix')]
    public function test_login_and_direct_requests_follow_platform_matrix(string $role, ?string $position, array $platforms): void
    {
        $user = $this->account($role, $position);
        $mobile = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'platform' => 'web']);
        if (in_array('mobile', $platforms, true)) {
            $mobile->assertOk()->assertJsonPath('data.allowed_platforms', $platforms);
            $token = $mobile->json('data.token');
            $this->assertSame(['platform:mobile'], $user->tokens()->firstOrFail()->abilities);
            $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.allowed_platforms', $platforms);
            $user->update(['is_active' => false]);
            $this->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('code', 'SESSION_REVOKED');
            $user->update(['is_active' => true]);
        } else {
            $mobile->assertForbidden();
            $token = $user->createToken('forged channel', ['platform:mobile'])->plainTextToken;
            $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
        }

        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->flushHeaders();
        $login = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        if (in_array('web', $platforms, true)) {
            $login->assertRedirect('/app');
            $this->get('/csrf-token')->assertOk();
            $user->update(['is_active' => false]);
            $this->get('/csrf-token')->assertRedirect('/login');
        } else {
            $login->assertSessionHasErrors('email');
            $this->actingAs($user, 'web')->get('/csrf-token')->assertRedirect('/login');
        }
    }

    public function test_old_tokens_and_web_sessions_cannot_impersonate_mobile(): void
    {
        $user = $this->account('employee', 'POS-KSR');
        $legacy = $user->createToken('old wildcard')->plainTextToken;
        $this->withToken($legacy)->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('code', 'SESSION_REVOKED');
        $this->assertSame(0, $user->tokens()->count());
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->flushHeaders();
        $this->actingAs($user, 'web')->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_existing_sessions_recheck_role_and_account_status(): void
    {
        $user = $this->account('employee', 'POS-KSR');
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        Role::findOrCreate('auditor', 'web');
        $user->syncRoles('auditor');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('code', 'SESSION_REVOKED');

        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->flushHeaders();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/app');
        User::whereKey($user->id)->update(['is_active' => false]);
        $this->get('/csrf-token')->assertRedirect('/login');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
    }

    public function test_operational_login_requires_active_complete_employee_profile(): void
    {
        $user = $this->account('employee', null);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertForbidden();
        $staff = $this->account('employee', 'POS-KSR');
        $staff->employee->update(['status' => 'inactive']);
        $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'password'])->assertForbidden();
    }

    public function test_kpi_admin_and_auditor_have_no_operational_or_scoring_authority(): void
    {
        foreach (['kpi_admin', 'auditor'] as $role) {
            $user = $this->account($role, 'POS-KSR');
            $this->assertFalse(CapabilityMatrix::has($user, 'tickets.payment'));
            $this->assertFalse(CapabilityMatrix::has($user, 'kpi.self.view'));
            $this->actingAs($user, 'web')->get('/app/service-tickets')->assertForbidden();
            $this->actingAs($user, 'web')->post('/app/employee-kpis/1/actions/recalculate')->assertNotFound();
        }
        $auditor = User::role('auditor')->firstOrFail();
        $this->actingAs($auditor, 'web')->get('/app/audit-events')->assertOk();
        $this->actingAs($auditor, 'web')->post('/app/kpi-periods', [])->assertForbidden();
        $this->actingAs($auditor, 'web')->post('/app/users', [])->assertForbidden();
    }

    public function test_super_admin_has_all_web_capabilities_but_still_cannot_use_mobile(): void
    {
        $admin = $this->account('super_admin', null);

        $this->assertTrue(CapabilityMatrix::has($admin, 'tickets.payment'));
        $this->assertTrue(CapabilityMatrix::has($admin, 'kpi.manager.approval'));
        $this->actingAs($admin, 'web')->get('/app/service-tickets')->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/resources/service-tickets')->assertForbidden();
    }

    public function test_system_admin_rejects_conflicting_roles_and_exposes_account_linkage(): void
    {
        $admin = $this->account('super_admin', null);
        $employeeRole = Role::findOrCreate('employee', 'web');
        $this->actingAs($admin, 'web')->post('/app/users', [
            'name' => 'Peran konflik', 'email' => 'conflict@example.test', 'password' => 'password',
            'role_ids' => [$admin->roles->first()->id, $employeeRole->id], 'is_active' => true,
        ])->assertSessionHasErrors('role_ids');
        $this->assertDatabaseMissing('users', ['email' => 'conflict@example.test']);
        $this->get('/app/users')->assertOk();
        $this->get('/app/users/create')->assertOk();
    }

    private function account(string $role, ?string $position): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        if ($position) {
            $branch = Branch::firstOrCreate(['code' => 'TEST'], ['name' => 'Cabang Tes', 'is_active' => true]);
            $job = Position::firstOrCreate(['code' => $position], ['name' => $position, 'department' => 'Operasional', 'is_active' => true]);
            Employee::create([
                'user_id' => $user->id, 'employee_number' => 'EMP-'.$user->id, 'name' => $user->name,
                'email' => $user->email, 'position_id' => $job->id, 'branch_id' => $branch->id,
                'joined_at' => '2026-01-01', 'status' => 'active',
            ]);
        }

        return $user->fresh(['roles', 'employee.position', 'employee.branch']);
    }
}
