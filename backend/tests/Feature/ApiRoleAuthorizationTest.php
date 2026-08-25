<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_employee_cannot_access_manager_or_supervisor_endpoints(): void
    {
        $userTek = User::where('email', 'teknisi@toko.com')->first();
        $this->assertNotNull($userTek);

        $this->actingAs($userTek, 'sanctum')->getJson('/api/v1/manager/queue')->assertForbidden();
        $this->actingAs($userTek, 'sanctum')->getJson('/api/v1/supervisor/queue')->assertForbidden();
        $this->actingAs($userTek, 'sanctum')->postJson('/api/v1/manager/approval/1/approve')->assertForbidden();
    }

    public function test_manager_can_access_manager_endpoints_but_not_supervisor(): void
    {
        $userMgr = User::where('email', 'manager@toko.com')->first();
        $this->assertNotNull($userMgr);

        $this->actingAs($userMgr, 'sanctum')->getJson('/api/v1/manager/queue')->assertOk();
        $this->actingAs($userMgr, 'sanctum')->getJson('/api/v1/supervisor/queue')->assertForbidden();
    }

    public function test_supervisor_can_access_supervisor_endpoints_but_not_manager(): void
    {
        $userSpv = User::where('email', 'supervisor@toko.com')->first();
        $this->assertNotNull($userSpv);

        $this->actingAs($userSpv, 'sanctum')->getJson('/api/v1/supervisor/queue')->assertOk();
        $this->actingAs($userSpv, 'sanctum')->getJson('/api/v1/manager/queue')->assertForbidden();
    }
}
