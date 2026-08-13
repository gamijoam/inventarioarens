<?php

namespace Tests\Feature\Console;

use App\Modules\LocalSupport\Services\LocalTechnicalConsoleService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RepairLocalTasksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_command_reports_systemd_on_non_windows(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Este test valida el comportamiento en Linux (CI).');
        }

        $this->artisan('local:repair-tasks', ['--printer' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Windows no detectado');
    }

    public function test_repair_command_is_registered(): void
    {
        $this->assertTrue(
            app(Kernel::class)->all()['local:repair-tasks'] !== null
            || array_key_exists('local:repair-tasks', app(Kernel::class)->all())
        );
    }

    public function test_repair_only_considers_tenants_with_tokens_on_windows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Este test valida el re-registro real de tareas en Windows.');
        }

        Tenant::create(['name' => 'Demo', 'slug' => 'demo-caracas']);
        $settingsPath = storage_path('app/sync-worker/sync-config.json');
        $previous = is_file($settingsPath) ? (string) file_get_contents($settingsPath) : null;
        File::ensureDirectoryExists(dirname($settingsPath));
        File::put($settingsPath, json_encode([
            'version' => 2,
            'tenants' => [
                'demo-caracas' => ['token' => 'token-a', 'interval' => 15, 'limit' => 50],
                'sin-token' => [],
            ],
        ]));

        try {
            $service = app(LocalTechnicalConsoleService::class);
            $result = $service->repairWindowsTasks(withPrinter: false);

            $joined = implode("\n", $result['output']);
            $this->assertStringContainsString('demo-caracas', $joined);
            $this->assertStringNotContainsString('sin-token', $joined);
        } finally {
            if ($previous === null) {
                File::delete($settingsPath);
            } else {
                File::put($settingsPath, $previous);
            }
        }
    }
}
