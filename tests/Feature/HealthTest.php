<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_reports_ok_when_database_is_reachable(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok']]);
    }

    public function test_health_reports_unavailable_when_database_is_down(): void
    {
        config([
            'database.connections.unreachable' => [
                'driver' => 'sqlite',
                'database' => '/nonexistent/team-ops.sqlite',
            ],
            'database.default' => 'unreachable',
        ]);

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable');
    }
}
