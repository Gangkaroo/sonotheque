<?php

namespace Tests\Feature;

use Tests\TestCase;

class LanTransportSecurityTest extends TestCase
{
    public function test_untrusted_hosts_are_rejected(): void
    {
        $this->get('http://untrusted.example/api/dashboard-metrics')
            ->assertBadRequest();
    }

    public function test_configured_cors_origin_is_allowed(): void
    {
        config(['cors.allowed_origins' => ['http://music-box.local']]);

        $this->call(
            'OPTIONS',
            '/api/dashboard-metrics',
            server: [
                'HTTP_ORIGIN' => 'http://music-box.local',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ],
        )
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://music-box.local');
    }

    public function test_unconfigured_cors_origin_is_not_allowed(): void
    {
        config(['cors.allowed_origins' => ['http://music-box.local']]);

        $response = $this->call(
            'OPTIONS',
            '/api/dashboard-metrics',
            server: [
                'HTTP_ORIGIN' => 'http://untrusted.example',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ],
        );

        $response->assertNoContent();
        $this->assertNotSame(
            'http://untrusted.example',
            $response->headers->get('Access-Control-Allow-Origin'),
        );
    }
}
