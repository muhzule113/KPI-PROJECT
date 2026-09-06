<?php

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-15 08:00:00', 'Asia/Makassar'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function actingAs(Authenticatable $user, $guard = null)
    {
        if ($guard === 'sanctum' && $user instanceof User) {
            $user->withAccessToken($user->createToken('feature-test', ['platform:mobile'])->accessToken);
        }

        return parent::actingAs($user, $guard ?? 'web');
    }

    public function createApplication()
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'mysql'
            || $app['config']->get('database.connections.mysql.database') !== 'kpi_management_test') {
            throw new \RuntimeException('Tes wajib memakai database MySQL kpi_management_test yang terpisah.');
        }

        return $app;
    }
}
