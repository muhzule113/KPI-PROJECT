<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileParityApiTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_manager_dashboard_and_report_are_available_with_shared_shapes(): void
    {
        $manager = User::where('email', 'manager@toko.com')->firstOrFail();

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'active_period',
                    'filters',
                    'metrics',
                    'trend',
                    'top_performers',
                    'recent_kpis',
                    'attention_kpis',
                    'notifications',
                    'my_kpi',
                    'executive_overview',
                ],
            ]);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/reports/kpi')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'period',
                    'rows',
                ],
            ]);
    }

    public function test_mobile_resource_access_follows_role_capabilities(): void
    {
        $supervisor = User::where('email', 'supervisor@toko.com')->firstOrFail();
        $pelayan = User::where('email', 'cs@toko.com')->firstOrFail();
        $technician = User::where('email', 'teknisi@toko.com')->firstOrFail();

        $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/v1/resources/coaching-logs')
            ->assertOk()
            ->assertJsonPath('data.resource.key', 'coaching-logs');

        $this->actingAs($pelayan, 'sanctum')
            ->getJson('/api/v1/resources/complaints')
            ->assertOk()
            ->assertJsonPath('data.resource.can_create', true)
            ->assertJsonPath('data.resource.header_actions', []);

        $this->actingAs($technician, 'sanctum')
            ->getJson('/api/v1/resources/complaints')
            ->assertForbidden();
    }

    public function test_non_manager_cannot_open_mobile_report(): void
    {
        $technician = User::where('email', 'teknisi@toko.com')->firstOrFail();

        $this->actingAs($technician, 'sanctum')
            ->getJson('/api/v1/reports/kpi')
            ->assertForbidden();
    }
}
