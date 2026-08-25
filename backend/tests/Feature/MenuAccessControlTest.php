<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MenuAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_super_admin_can_access_everything(): void
    {
        $user = User::where('email', 'admin@kpi.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('super_admin'));

        $this->assertTrue(MenuAccess::can($user, [], []));
        $this->assertTrue(MenuAccess::can($user, ['owner_manager'], []));
        $this->assertTrue(MenuAccess::can($user, [], ['POS-GUD']));
    }

    public function test_gudang_only_sees_inventory_and_service_tickets(): void
    {
        $user = User::where('email', 'gudang@toko.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('POS-GUD', $user->employee?->position?->code);

        // Boleh: inventory & sparepart & tiket servis
        $this->assertTrue(MenuAccess::can($user, [], ['POS-GUD']));
        $this->assertTrue(MenuAccess::can($user, [], ['POS-TEK', 'POS-CS', 'POS-GUD']));

        // Tidak boleh: resource manager/superadmin
        $this->assertFalse(MenuAccess::can($user, ['owner_manager'], []));
        $this->assertFalse(MenuAccess::can($user, ['supervisor'], []));
        $this->assertFalse(MenuAccess::can($user, [], ['POS-KSR']));
        $this->assertFalse(MenuAccess::can($user, [], ['POS-ADM']));
    }

    public function test_teknisi_only_sees_service_tickets(): void
    {
        $user = User::where('email', 'teknisi@toko.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('POS-TEK', $user->employee?->position?->code);

        $this->assertTrue(MenuAccess::can($user, [], ['POS-TEK', 'POS-CS', 'POS-GUD']));
        $this->assertFalse(MenuAccess::can($user, [], ['POS-GUD'])); // hanya inventory tidak
        $this->assertFalse(MenuAccess::can($user, ['supervisor'], []));
        $this->assertFalse(MenuAccess::can($user, ['owner_manager'], []));
    }

    public function test_owner_manager_sees_approval_and_period_but_not_gudang_only(): void
    {
        $user = User::where('email', 'manager@toko.com')->first();
        $this->assertNotNull($user);

        $this->assertTrue(MenuAccess::can($user, ['owner_manager'], []));
        $this->assertTrue(MenuAccess::can($user, ['owner_manager', 'super_admin'], []));
        $this->assertFalse(MenuAccess::can($user, [], ['POS-GUD']));
    }
}