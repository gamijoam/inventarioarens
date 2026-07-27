<?php

namespace Tests\Feature\LocalSupport;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LocalTechnicalConsoleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_technical_console_lists_local_tenants_only_when_enabled(): void
    {
        config()->set('services.local_support.enabled', true);
        config()->set('services.local_support.cloud_url', 'https://cloud.test/api');

        Tenant::create(['name' => 'Caracas local', 'slug' => 'caracas-local']);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/local-support/status')
            ->assertOk()
            ->assertJsonPath('data.cloud_url', 'https://cloud.test/api')
            ->assertJsonPath('data.tenants.0.slug', 'caracas-local')
            ->assertJsonPath('data.tenants.0.worker.active', false);
    }

    public function test_local_technical_console_is_hidden_when_not_enabled(): void
    {
        config()->set('services.local_support.enabled', false);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/local-support/status')
            ->assertNotFound();
    }

    public function test_local_technical_console_keeps_a_configured_company_visible_while_it_is_preparing(): void
    {
        config()->set('services.local_support.enabled', true);
        $path = storage_path('app/sync-worker/sync-config.json');
        $previous = File::exists($path) ? File::get($path) : null;
        File::ensureDirectoryExists(dirname($path));
        try {
            File::put($path, json_encode([
                'tenants' => [
                    'oscar-cell' => [
                        'tenant_name' => 'Oscar Cell',
                        'node_name' => 'Equipo local',
                        'node_code' => 'LOCAL-01',
                        'interval' => 15,
                        'token' => 'token-de-prueba',
                    ],
                ],
            ]));

            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
                ->getJson('/api/local-support/status')
                ->assertOk()
                ->assertJsonPath('data.tenants.0.slug', 'oscar-cell')
                ->assertJsonPath('data.tenants.0.name', 'Oscar Cell')
                ->assertJsonPath('data.tenants.0.ready', false)
                ->assertJsonPath('data.tenants.0.configured', true);
        } finally {
            $previous === null ? File::delete($path) : File::put($path, $previous);
        }
    }
}
