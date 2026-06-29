<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LanProtectionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_routes_are_available_from_localhost(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/api/library_roots', ['Accept' => 'application/ld+json'])
            ->assertOk();
    }

    public function test_sensitive_routes_are_blocked_from_lan_by_default(): void
    {
        $this->call(
            'GET',
            '/api/library_roots',
            server: [
                'REMOTE_ADDR' => '192.168.1.20',
                'HTTP_ACCEPT' => 'application/ld+json',
            ],
        )
            ->assertForbidden()
            ->assertJsonPath('message', 'This settings operation is only available locally or with a configured admin token.');
    }

    public function test_sensitive_routes_accept_configured_admin_token_when_lan_is_enabled(): void
    {
        config([
            'music-library.lan.enabled' => true,
            'music-library.lan.admin_token' => 'secret-token',
        ]);

        $this->call(
            'GET',
            '/api/library_roots',
            server: [
                'REMOTE_ADDR' => '192.168.1.20',
                'HTTP_ACCEPT' => 'application/ld+json',
                'HTTP_X_MUSIC_LIBRARY_ADMIN_TOKEN' => 'secret-token',
            ],
        )
            ->assertOk();
    }

    public function test_public_catalog_routes_remain_available_from_lan(): void
    {
        $this->call(
            'GET',
            '/api/dashboard-metrics',
            server: [
                'REMOTE_ADDR' => '192.168.1.20',
                'HTTP_ACCEPT' => 'application/json',
            ],
        )
            ->assertOk()
            ->assertExactJson([
                'artists' => 0,
                'albums' => 0,
                'tracks' => 0,
                'genres' => 0,
                'playedAlbums' => 0,
                'playedTracks' => 0,
            ]);
    }

    public function test_metadata_edit_routes_are_blocked_from_lan_by_default(): void
    {
        foreach ([
            ['POST', '/api/tracks/1/metadata/preview'],
            ['POST', '/api/tracks/1/metadata-edits'],
            ['POST', '/api/albums/1/metadata/preview'],
            ['POST', '/api/albums/1/metadata-edits'],
            ['GET', '/api/metadata-edits/1'],
            ['GET', '/api/settings/metadata-backups'],
            ['PATCH', '/api/settings/metadata-backups'],
        ] as [$method, $uri]) {
            $this->call(
                $method,
                $uri,
                server: [
                    'REMOTE_ADDR' => '192.168.1.20',
                    'HTTP_ACCEPT' => 'application/json',
                ],
            )->assertForbidden();
        }
    }
}
