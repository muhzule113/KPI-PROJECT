<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function test_local_csp_allows_reverb_websocket_transport(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString(' ws:', $csp);
    }
}
