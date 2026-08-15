<?php

namespace Tests\Feature\LocalSupport;

use App\Modules\LocalSupport\Services\LocalTechnicalConsoleService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
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
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Este test valida el comportamiento en Linux (CI); en Windows el worker reporta available=true.');
        }

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

    public function test_connect_uses_the_central_sync_service_when_running_under_the_local_motor(): void
    {
        config()->set('services.local_support.enabled', true);
        config()->set('services.local_support.cloud_url', 'https://cloud.test/api');
        config()->set('services.local_support.service_mode', true);
        Http::fake([
            'https://cloud.test/api/sync/pairing-codes/redeem' => Http::response([
                'data' => [
                    'tenant' => ['name' => 'Empresa Motor', 'slug' => 'empresa-motor'],
                    'token' => 'sync-token',
                ],
            ], 201),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/local-support/connect', [
                'code' => str_repeat('M', 40),
                'node_name' => 'Equipo Motor',
                'node_code' => 'MOTOR-01',
                'interval' => 15,
                'local_email' => 'tecnico@motor.test',
                'local_password' => 'password123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.worker.status.service', 'SistemaInventarioSync')
            ->assertJsonPath('data.worker.status.service_manager', 'scm')
            ->assertJsonPath('data.worker.output', 'La sincronizacion se ejecuta mediante el servicio central SistemaInventarioSync.');
    }

    public function test_per_tenant_worker_actions_are_rejected_when_the_local_motor_owns_sync(): void
    {
        config()->set('services.local_support.enabled', true);
        config()->set('services.local_support.service_mode', true);
        $path = storage_path('app/sync-worker/sync-config.json');
        $previous = File::exists($path) ? File::get($path) : null;
        File::ensureDirectoryExists(dirname($path));

        try {
            File::put($path, json_encode([
                'tenants' => [
                    'empresa-motor' => [
                        'tenant_name' => 'Empresa Motor',
                        'node_name' => 'Equipo Motor',
                        'node_code' => 'MOTOR-01',
                        'interval' => 15,
                        'token' => 'sync-token',
                        'cloud_url' => 'https://cloud.test/api',
                    ],
                ],
            ]));
            Tenant::create(['name' => 'Empresa Motor', 'slug' => 'empresa-motor']);

            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
                ->postJson('/api/local-support/tenants/empresa-motor/worker', ['action' => 'restart'])
                ->assertUnprocessable()
                ->assertJsonPath('errors.worker.0', 'La sincronizacion se controla mediante el servicio central SistemaInventarioSync.');
        } finally {
            $previous === null ? File::delete($path) : File::put($path, $previous);
        }
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
                            'token' => ['token' => 'group-token', 'token_type' => 'Bearer'],
                        ],
                        [
                            'tenant' => ['id' => 3, 'name' => 'Hija', 'slug' => 'hija', 'parent_id' => 2, 'is_group' => false],
                            'token' => ['token' => 'child-token', 'token_type' => 'Bearer'],
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

    public function test_connect_imports_and_confirms_the_initial_bootstrap_before_starting_sync(): void
    {
        config()->set('services.local_support.enabled', true);
        config()->set('services.local_support.cloud_url', 'https://cloud.test/api');
        Http::fake([
            'https://cloud.test/api/sync/pairing-codes/redeem' => Http::response([
                'data' => [
                    'bootstrap_required' => true,
                    'tenant' => [
                        'id' => 501,
                        'name' => 'Empresa bootstrap',
                        'slug' => 'empresa-bootstrap',
                        'parent_id' => null,
                        'is_group' => false,
                    ],
                    'token' => 'bootstrap-token',
                ],
            ], 201),
            'https://cloud.test/api/sync/bootstrap' => Http::response([
                'data' => [
                    'session' => ['token' => 'bootstrap-session-token'],
                    'snapshot' => [
                        'version' => 1,
                        'events' => [[
                            'event_uuid' => '50111111-1111-1111-1111-111111111111',
                            'event_type' => 'product.created',
                            'aggregate_type' => 'product',
                            'aggregate_id' => 9001,
                            'payload' => [
                                'sku' => 'BOOT-LOCAL-001',
                                'name' => 'Producto local bootstrap',
                                'tracking_type' => 'quantity',
                                'base_price' => '15.0000',
                                'sale_currency' => 'USD',
                                'is_active' => true,
                            ],
                            'created_at' => now()->subMinute()->toISOString(),
                            'updated_at' => now()->subMinute()->toISOString(),
                        ]],
                    ],
                ],
            ], 201),
            'https://cloud.test/api/sync/bootstrap/*/complete' => Http::response([
                'data' => ['status' => 'completed'],
            ]),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/local-support/connect', [
                'code' => str_repeat('C', 40),
                'node_name' => 'Equipo bootstrap',
                'node_code' => 'BOOTSTRAP-01',
                'interval' => 15,
                'local_email' => 'tecnico@bootstrap.test',
                'local_password' => 'password123',
                'selected_tenant_ids' => [501],
            ])
            ->assertCreated()
            ->assertJsonPath('data.download.status', 'completed')
            ->assertJsonPath('data.bootstrap.summary.applied', 1);

        $this->assertDatabaseHas('products', [
            'tenant_id' => DB::table('tenants')->where('slug', 'empresa-bootstrap')->value('id'),
            'sku' => 'BOOT-LOCAL-001',
        ]);
        $localTenantId = DB::table('tenants')->where('slug', 'empresa-bootstrap')->value('id');
        $this->assertDatabaseHas('sync_tenant_readiness', [
            'tenant_id' => $localTenantId,
            'status' => 'ready',
        ]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sync/bootstrap'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sync/bootstrap/') && str_ends_with($request->url(), '/complete'));
    }

    public function test_printer_test_reports_agent_unreachable_until_installed(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Este escenario usa systemd y no debe iniciar tareas reales de Windows durante el test.');
        }

        config()->set('services.local_support.enabled', true);
        Http::fake([
            'http://127.0.0.1:17777/*' => function () {
                throw new ConnectionException('Connection refused');
            },
        ]);

        // El test corre en el entorno de CI (Linux); el arranque bajo demanda
        // delega en systemctl que no existe, por lo que el health sigue fallando.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/local-support/printer/test')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.status.available', false);
    }

    public function test_printer_test_reports_agent_healthy_when_responding(): void
    {
        config()->set('services.local_support.enabled', true);
        Http::fake([
            'http://127.0.0.1:17777/health' => Http::response(['ok' => true, 'service' => 'inventarioarens-printer-agent'], 200),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/local-support/printer/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.status.available', true);
    }

    public function test_printer_action_rejects_invalid_action(): void
    {
        config()->set('services.local_support.enabled', true);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/local-support/printer/action', ['action' => 'explode'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action']);
    }

    public function test_repair_windows_tasks_uses_systemd_on_non_windows(): void
    {
        config()->set('services.local_support.enabled', true);
        $service = app(LocalTechnicalConsoleService::class);

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Este test valida el comportamiento en Linux (CI).');
        }

        $result = $service->repairWindowsTasks();

        $this->assertCount(1, $result['output']);
        $this->assertStringContainsString('systemd', $result['output'][0]);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_printer_task_command_uses_wscript_hidden_runner(): void
    {
        config()->set('services.local_support.enabled', true);
        $service = app(LocalTechnicalConsoleService::class);

        $method = new \ReflectionMethod($service, 'printerTaskCommand');
        $method->setAccessible(true);
        $command = $method->invoke($service, 'C:\\app\\scripts\\run-sync-hidden.vbs', 'C:\\app\\storage\\app\\printer-agent\\printer-agent.cmd');

        $this->assertStringContainsString('wscript.exe', $command);
        $this->assertStringContainsString('run-sync-hidden.vbs', $command);
        $this->assertStringContainsString('printer-agent.cmd', $command);
        $this->assertStringNotContainsString('cmd /c', $command);
    }

    public function test_printer_launcher_bundles_storage_php_and_printer_serve(): void
    {
        config()->set('services.local_support.enabled', true);
        $service = app(LocalTechnicalConsoleService::class);

        $method = new \ReflectionMethod($service, 'printerLauncherContent');
        $method->setAccessible(true);
        $content = $method->invoke($service);

        $this->assertStringContainsString('cd /d ', $content);
        $this->assertStringContainsString('LARAVEL_STORAGE_PATH=', $content);
        $this->assertStringContainsString('DB_DATABASE=', $content);
        $this->assertStringContainsString('printer:serve --port=17777 --bind=127.0.0.1', $content);
        $this->assertStringContainsString(PHP_BINARY, $content);
        $this->assertStringContainsString(storage_path(), $content);
        $this->assertStringContainsString('start "" /b', $content);
        $this->assertStringNotContainsString('cmd /c', $content);
        $this->assertStringContainsString('printer-agent.pid', $content);
        $this->assertStringContainsString('printer-agent.log', $content);
    }

    public function test_worker_launcher_bundles_storage_php_and_tls_scan_dir(): void
    {
        config()->set('services.local_support.enabled', true);
        $service = app(LocalTechnicalConsoleService::class);

        $method = new \ReflectionMethod($service, 'workerLauncherContent');
        $method->setAccessible(true);
        $content = $method->invoke($service, 'oscar-cell', 'C:\\app\\scripts\\sync-worker.cmd');

        $this->assertStringContainsString('cd /d ', $content);
        $this->assertStringContainsString('LARAVEL_STORAGE_PATH=', $content);
        $this->assertStringContainsString('DB_DATABASE=', $content);
        $this->assertStringContainsString((string) config('database.connections.sqlite.database'), $content);
        $this->assertStringContainsString('-TenantSlug "oscar-cell"', $content);
        $this->assertStringContainsString('-PhpPath "', $content);
        $this->assertStringContainsString('call "C:\\app\\scripts\\sync-worker.cmd" run ', $content);
        $this->assertStringNotContainsString(' start -TenantSlug', $content);
        $this->assertStringContainsString(PHP_BINARY, $content);
        $this->assertStringContainsString(storage_path(), $content);
    }

    public function test_worker_task_command_stays_within_schtasks_261_char_limit(): void
    {
        config()->set('services.local_support.enabled', true);
        $service = app(LocalTechnicalConsoleService::class);

        $method = new \ReflectionMethod($service, 'workerTaskCommand');
        $method->setAccessible(true);
        $longVbs = 'C:\\Program Files\\Soporte-Tecnico-Inventario\\resources\\backend\\scripts\\run-sync-hidden.vbs';
        $longLauncher = 'C:\\ProgramData\\InventarioArens\\storage\\app\\sync-worker\\sync-task-oscarcell-tucacas-grande.cmd';
        $command = $method->invoke($service, $longVbs, $longLauncher);

        $this->assertLessThanOrEqual(261, strlen($command));
        $this->assertStringContainsString('wscript.exe', $command);
        $this->assertMatchesRegularExpression('/\.VBS"/i', $command);
        $this->assertMatchesRegularExpression('/\.CMD"$/i', $command);
    }

    public function test_worker_task_creation_uses_the_same_system_identity_as_the_backend(): void
    {
        config()->set('services.local_support.enabled', true);
        $service = app(LocalTechnicalConsoleService::class);

        $method = new \ReflectionMethod($service, 'workerTaskCreateArguments');
        $method->setAccessible(true);
        $arguments = $method->invoke($service, 'SistemaInventarioSync-oscar-cell', 'worker-command');

        $this->assertSame('SYSTEM', $arguments[array_search('/RU', $arguments, true) + 1]);
        $this->assertSame('HIGHEST', $arguments[array_search('/RL', $arguments, true) + 1]);
    }

    public function test_local_support_does_not_reduce_the_cli_execution_time_limit(): void
    {
        set_time_limit(0);
        $service = app(LocalTechnicalConsoleService::class);
        $method = new \ReflectionMethod($service, 'extendExecutionTime');
        $method->setAccessible(true);

        $method->invoke($service);

        $this->assertSame('0', ini_get('max_execution_time'));
    }

    public function test_connect_reports_dns_failure_with_helpful_message(): void
    {
        config()->set('services.local_support.enabled', true);
        config()->set('services.local_support.cloud_url', 'https://nonexistent.invalid.test/api');

        Http::fake(function () {
            throw new ConnectionException('Could not resolve host: nonexistent.invalid.test');
        });

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/local-support/connect', [
                'code' => str_repeat('A', 40),
                'node_name' => 'Equipo Test',
                'node_code' => 'TEST-01',
                'interval' => 15,
                'local_email' => 'tecnico@test.test',
                'local_password' => 'password123',
            ]);

        $response->assertStatus(422);
        $message = (string) $response->json('errors.code.0');
        $this->assertStringContainsString('No fue posible conectar con la nube', $message);
        $this->assertStringContainsString('nonexistent.invalid.test', $message);
    }

    public function test_connect_reports_html_response_from_cloud_as_misrouted_error(): void
    {
        config()->set('services.local_support.enabled', true);
        config()->set('services.local_support.cloud_url', 'https://cloud.test/api');

        Http::fake([
            'cloud.test/*' => Http::response(
                '<!doctype html><html><head><title>SPA</title></head><body></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/local-support/connect', [
                'code' => str_repeat('A', 40),
                'node_name' => 'Equipo Test',
                'node_code' => 'TEST-01',
                'interval' => 15,
                'local_email' => 'tecnico@test.test',
                'local_password' => 'password123',
            ]);

        $response->assertStatus(422);
        $message = (string) $response->json('errors.code.0');
        $this->assertStringContainsString('HTML', $message);
        $this->assertStringContainsString('cloud.test', $message);
        $this->assertStringContainsString('Traefik', $message);
        $this->assertStringContainsString('puerto 8080', $message);
    }

    public function test_windows_console_output_is_normalized_before_returning_json(): void
    {
        $service = app(LocalTechnicalConsoleService::class);
        $method = new \ReflectionMethod($service, 'normalizeExternalText');
        $method->setAccessible(true);
        $cp850 = mb_convert_encoding('Operación completada: sincronización técnica.', 'CP850', 'UTF-8');

        $normalized = $method->invoke($service, $cp850);

        $this->assertSame('Operación completada: sincronización técnica.', $normalized);
        $this->assertTrue(mb_check_encoding($normalized, 'UTF-8'));
        $this->assertNotFalse(json_encode(['output' => $normalized], JSON_THROW_ON_ERROR));
    }

    public function test_connect_response_substitutes_an_unexpected_invalid_utf8_byte(): void
    {
        config()->set('services.local_support.enabled', true);
        $console = \Mockery::mock(LocalTechnicalConsoleService::class);
        $console->shouldReceive('assertAvailable')->once();
        $console->shouldReceive('connect')->once()->andReturn([
            'worker' => ['output' => "Salida de Windows \x82"],
        ]);
        $this->app->instance(LocalTechnicalConsoleService::class, $console);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/local-support/connect', [
                'code' => str_repeat('C', 40),
                'node_name' => 'Equipo Test',
                'node_code' => 'TEST-01',
                'interval' => 15,
                'local_email' => 'tecnico@test.test',
                'local_password' => 'password123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.worker.output', 'Salida de Windows �');
    }
}
