<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\BalanzaProDiagnosticsServer;
use App\Mcp\Tools\SystemHealthTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalanzaProDiagnosticsServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_the_read_only_system_health_tool(): void
    {
        BalanzaProDiagnosticsServer::tools()
            ->assertRegistered(SystemHealthTool::class);
    }

    public function test_it_returns_safe_application_and_database_health(): void
    {
        BalanzaProDiagnosticsServer::tool(SystemHealthTool::class)
            ->assertOk()
            ->assertStructuredContent([
                'application' => [
                    'name' => 'Balanza Pro',
                    'laravel_version' => app()->version(),
                    'environment' => 'testing',
                ],
                'database' => [
                    'connection' => config('database.default'),
                    'driver' => 'sqlite',
                    'status' => 'ok',
                ],
            ]);
    }
}
