<?php

namespace Tests\Feature\LocalSupport;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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

    public function test_lan_server_mode_is_disabled_by_default_and_can_be_configured_locally(): void
    {
        config()->set('services.local_support.enabled', true);
        $path = dirname((string) config('database.connections.sqlite.database')).'/local-server.json';
        $previous = File::exists($path) ? File::get($path) : null;
        File::delete($path);

        try {
            $initial = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
                ->getJson('/api/local-support/status')
                ->assertOk();
            $initial
                ->assertJsonPath('data.lan.enabled', false)
                ->assertJsonPath('data.lan.bind_host', '127.0.0.1');

            $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
                ->postJson('/api/local-support/server-mode', ['enabled' => true])
                ->assertOk();
            $response
                ->assertJsonPath('data.enabled', true)
                ->assertJsonPath('data.bind_host', '0.0.0.0')
                ->assertJsonPath('data.restart_required', true);
        } finally {
            $previous === null ? File::delete($path) : File::put($path, $previous);
        }
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

    public function test_connect_does_not_try_to_install_a_windows_worker_on_linux(): void
    {
        config()->set('services.local_support.enabled', true);
        config()->set('services.local_support.cloud_url', 'https://cloud.test/api');
        Http::fake([
            'https://cloud.test/api/sync/pairing-codes/redeem' => Http::response([
                'data' => [
                    'tenant' => ['name' => 'Empresa local', 'slug' => 'empresa-local'],
                    'token' => 'sync-token',
                ],
            ], 201),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/local-support/connect', [
                'code' => str_repeat('A', 40),
                'node_name' => 'Equipo Linux',
                'node_code' => 'LINUX-01',
                'interval' => 15,
                'local_email' => 'tecnico@empresa.test',
                'local_user_name' => 'Tecnico local',
                'local_password' => 'password123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tenant.slug', 'empresa-local')
            ->assertJsonPath('data.worker.status.available', false);

        $this->assertDatabaseHas('tenants', ['slug' => 'empresa-local']);
        Http::assertSent(fn ($request) => $request->url() === 'https://cloud.test/api/sync/pairing-codes/redeem');
    }

    public function test_connect_prepares_every_tenant_returned_by_a_group_bundle(): void
    {
        config()->set('services.local_support.enabled', true);
        config()->set('services.local_support.cloud_url', 'https://cloud.test/api');
        Http::fake([
            'https://cloud.test/api/sync/pairing-codes/redeem' => Http::response([
                'data' => [
                    'group' => ['id' => 2, 'name' => 'Grupo', 'slug' => 'grupo', 'parent_id' => null, 'is_group' => true],
                    'tenants' => [
                        [
                            'tenant' => ['id' => 2, 'name' => 'Grupo', 'slug' => 'grupo', 'parent_id' => null, 'is_group' => true],
                            'token' => 'group-token',
                        ],
                        [
                            'tenant' => ['id' => 3, 'name' => 'Hija', 'slug' => 'hija', 'parent_id' => 2, 'is_group' => false],
                            'token' => 'child-token',
                        ],
                    ],
                ],
            ], 201),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/local-support/connect', [
                'code' => str_repeat('B', 40),
                'node_name' => 'Equipo Grupo',
                'node_code' => 'GROUP-01',
                'interval' => 15,
                'local_email' => 'tecnico@grupo.test',
                'local_password' => 'password123',
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.tenants');

        $settings = json_decode(
            (string) File::get(storage_path('app/sync-worker/sync-config.json')),
            true,
        );

        $this->assertSame('group-token', $settings['tenants']['grupo']['token']);
        $this->assertSame('child-token', $settings['tenants']['hija']['token']);
        $this->assertSame(2, $settings['tenants']['hija']['remote_parent_id']);
    }
}
