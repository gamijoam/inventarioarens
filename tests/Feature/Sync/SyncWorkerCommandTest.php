<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SyncWorkerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_worker_pushes_local_events_and_pulls_cloud_events(): void
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Sync Worker',
            'slug' => 'empresa-sync-worker',
        ]);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, ['status' => 'active']);

        $localEventUuid = (string) Str::uuid();
        $cloudEventUuid = (string) Str::uuid();
        $now = now();

        DB::table('sync_outbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $localEventUuid,
            'target_scope' => 'tenant',
            'event_type' => 'pos.order.paid',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => 15,
            'payload' => json_encode(['order_id' => 15, 'total_base_amount' => '20.0000']),
            'occurred_at' => $now,
            'available_at' => $now,
            'status' => 'pending',
            'idempotency_key' => 'pos-order-15',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $productId = DB::table('products')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Adaptador Bluetooth',
            'sku' => 'ADP-BT-CCS',
            'tracking_type' => 'quantity',
            'base_price' => '20.0000',
            'sale_currency' => 'USD',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $priceListId = DB::table('price_lists')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Detal',
            'code' => 'DETAL',
            'description' => null,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('product_prices')->insert([
            'tenant_id' => $tenant->id,
            'product_id' => $productId,
            'price_list_id' => $priceListId,
            'price' => '20.0000',
            'currency' => 'USD',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Http::fake([
            'https://cloud.test/api/sync/nodes' => Http::response([
                'data' => ['code' => 'LOCAL-VAL-01'],
            ], 201),
            'https://cloud.test/api/sync/events/push' => Http::response([
                'data' => ['received' => 1, 'duplicated' => 0],
            ], 202),
            'https://cloud.test/api/sync/events/pull*' => Http::response([
                'data' => [[
                    'id' => 99,
                    'event_uuid' => $cloudEventUuid,
                    'event_type' => 'product_price.updated',
                    'aggregate_type' => 'product_price',
                    'aggregate_id' => 44,
                    'payload' => [
                        'sku' => 'ADP-BT-CCS',
                        'price_list_code' => 'DETAL',
                        'price' => '30.0000',
                        'currency' => 'USD',
                        'is_active' => true,
                    ],
                ]],
            ], 200),
            "https://cloud.test/api/sync/events/{$cloudEventUuid}/ack" => Http::response([
                'data' => ['event_uuid' => $cloudEventUuid, 'status' => 'processed'],
            ], 200),
        ]);

        $this->artisan('sync:run', [
            'tenant' => $tenant->slug,
            '--node' => 'LOCAL-VAL-01',
            '--name' => 'Local Valencia 01',
            '--cloud-url' => 'https://cloud.test/api',
            '--token' => 'token-demo',
            '--limit' => 10,
            '--installation' => 'LOCAL-WORKER-PC-01',
        ])
            ->expectsOutput('Sincronizacion ejecutada.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_uuid' => $localEventUuid,
            'status' => 'processed',
        ]);
        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'event_uuid' => $cloudEventUuid,
            'event_type' => 'product_price.updated',
            'status' => 'applied',
        ]);
        $this->assertDatabaseHas('product_prices', [
            'tenant_id' => $tenant->id,
            'product_id' => $productId,
            'price_list_id' => $priceListId,
            'price' => '30.0000',
        ]);
        $this->assertDatabaseHas('sync_nodes', [
            'tenant_id' => $tenant->id,
            'code' => 'LOCAL-VAL-01',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('sync_states', [
            'tenant_id' => $tenant->id,
            'direction' => 'push',
            'last_event_uuid' => $localEventUuid,
        ]);
        $this->assertDatabaseHas('sync_states', [
            'tenant_id' => $tenant->id,
            'direction' => 'pull',
            'last_event_uuid' => $cloudEventUuid,
        ]);
        $this->assertDatabaseHas('sync_tenant_readiness', [
            'tenant_id' => $tenant->id,
            'installation_code' => 'LOCAL-WORKER-PC-01',
            'node_code' => 'LOCAL-VAL-01',
            'status' => 'ready',
        ]);

        Http::assertSentCount(4);
    }

    public function test_sync_worker_applies_customer_created_from_cloud(): void
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Sync Cliente Nube',
            'slug' => 'empresa-sync-cliente-nube',
        ]);
        $cloudEventUuid = (string) Str::uuid();

        Http::fake([
            'https://cloud.test/api/sync/nodes' => Http::response([
                'data' => ['code' => 'LOCAL-VAL-CUSTOMERS'],
            ], 201),
            'https://cloud.test/api/sync/events/pull*' => Http::response([
                'data' => [[
                    'id' => 141,
                    'event_uuid' => $cloudEventUuid,
                    'event_type' => 'customer.created',
                    'aggregate_type' => 'customer',
                    'aggregate_id' => 88,
                    'payload' => [
                        'name' => 'Cliente Web Nube',
                        'document_type' => 'V',
                        'document_number' => '88442211',
                        'phone' => '04148844221',
                        'email' => 'cliente.web@example.com',
                        'fiscal_address' => 'Valencia',
                        'is_generic' => false,
                        'is_active' => true,
                    ],
                ]],
            ], 200),
            "https://cloud.test/api/sync/events/{$cloudEventUuid}/ack" => Http::response([
                'data' => ['event_uuid' => $cloudEventUuid, 'status' => 'processed'],
            ], 200),
        ]);

        $this->artisan('sync:run', [
            'tenant' => $tenant->slug,
            '--node' => 'LOCAL-VAL-CUSTOMERS',
            '--name' => 'Local Valencia Clientes',
            '--cloud-url' => 'https://cloud.test/api',
            '--token' => 'token-demo',
            '--limit' => 10,
            '--pull-only' => true,
            '--installation' => 'LOCAL-WORKER-CUSTOMERS-01',
        ])
            ->expectsOutput('Sincronizacion ejecutada.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $tenant->id,
            'document_type' => 'V',
            'document_number' => '88442211',
            'name' => 'Cliente Web Nube',
            'email' => 'cliente.web@example.com',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'event_uuid' => $cloudEventUuid,
            'event_type' => 'customer.created',
            'status' => 'applied',
        ]);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), "/sync/events/{$cloudEventUuid}/ack"));
    }

    public function test_sync_apply_inbox_recovers_ignored_customer_events(): void
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Sync Cliente Ignorado',
            'slug' => 'empresa-sync-cliente-ignorado',
        ]);
        $eventUuid = (string) Str::uuid();
        $now = now();

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'origin_node_id' => null,
            'event_type' => 'customer.created',
            'aggregate_type' => 'customer',
            'aggregate_id' => 90,
            'payload_hash' => hash('sha256', json_encode([
                'name' => 'Soledad',
                'document_type' => 'V',
                'document_number' => '333333',
                'phone' => '00000',
                'email' => 'cliente@gmail.com',
                'fiscal_address' => 'Sin direccion',
                'is_generic' => false,
                'is_active' => true,
            ])),
            'payload' => json_encode([
                'name' => 'Soledad',
                'document_type' => 'V',
                'document_number' => '333333',
                'phone' => '00000',
                'email' => 'cliente@gmail.com',
                'fiscal_address' => 'Sin direccion',
                'is_generic' => false,
                'is_active' => true,
            ]),
            'status' => 'ignored',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->artisan('sync:apply-inbox', [
            'tenant' => $tenant->slug,
            '--limit' => 10,
        ])
            ->expectsOutput('Eventos recibidos procesados.')
            ->expectsOutput('Aplicados: 1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $tenant->id,
            'document_type' => 'V',
            'document_number' => '333333',
            'name' => 'Soledad',
            'email' => 'cliente@gmail.com',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'status' => 'applied',
        ]);
    }

    public function test_sync_worker_does_not_acknowledge_cloud_events_that_fail_locally(): void
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Sync Worker Falla',
            'slug' => 'empresa-sync-worker-falla',
        ]);
        $cloudEventUuid = (string) Str::uuid();

        Http::fake([
            'https://cloud.test/api/sync/nodes' => Http::response([
                'data' => ['code' => 'LOCAL-VAL-FAIL'],
            ], 201),
            'https://cloud.test/api/sync/events/pull*' => Http::response([
                'data' => [[
                    'id' => 101,
                    'event_uuid' => $cloudEventUuid,
                    'event_type' => 'product_price.updated',
                    'aggregate_type' => 'product_price',
                    'aggregate_id' => 77,
                    'payload' => [
                        'sku' => 'NO-EXISTE',
                        'price_list_code' => 'DETAL',
                        'price' => '30.0000',
                        'currency' => 'USD',
                        'is_active' => true,
                    ],
                ]],
            ], 200),
            "https://cloud.test/api/sync/events/{$cloudEventUuid}/ack" => Http::response([
                'data' => ['event_uuid' => $cloudEventUuid, 'status' => 'processed'],
            ], 200),
        ]);

        $this->artisan('sync:run', [
            'tenant' => $tenant->slug,
            '--node' => 'LOCAL-VAL-FAIL',
            '--name' => 'Local Valencia Fallo',
            '--cloud-url' => 'https://cloud.test/api',
            '--token' => 'token-demo',
            '--limit' => 10,
            '--pull-only' => true,
            '--installation' => 'LOCAL-WORKER-FAIL-01',
        ])
            ->expectsOutput('Sincronizacion ejecutada.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'event_uuid' => $cloudEventUuid,
            'status' => 'failed',
        ]);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), "/sync/events/{$cloudEventUuid}/ack"));
    }

    public function test_sync_worker_does_not_run_for_unknown_tenant(): void
    {
        Http::fake();

        $this->artisan('sync:run', [
            'tenant' => 'empresa-inexistente',
            '--cloud-url' => 'https://cloud.test/api',
            '--token' => 'token-demo',
        ])
            ->expectsOutput('No se encontro la empresa indicada.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_sync_issue_token_creates_token_for_tenant_user(): void
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Token Sync',
            'slug' => 'empresa-token-sync',
        ]);
        $user = User::factory()->create([
            'email' => 'sync@example.test',
            'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($tenant->id, ['status' => 'active']);

        $this->artisan('sync:issue-token', [
            'tenant' => $tenant->slug,
            'email' => $user->email,
            '--name' => 'worker-prueba',
            '--days' => 30,
        ])
            ->expectsOutput('Token de sincronizacion emitido.')
            ->expectsOutput('Empresa: empresa-token-sync')
            ->expectsOutput('Usuario: sync@example.test')
            ->assertExitCode(0);

        $this->assertDatabaseHas('auth_tokens', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'worker-prueba',
            'ip_address' => 'cli',
            'user_agent' => 'sync:issue-token',
        ]);
    }

    public function test_sync_prepare_local_creates_tenant_user_and_admin_role(): void
    {
        putenv('SYNC_BOOTSTRAP_PASSWORD=clave-local-segura');

        try {
            $this->artisan('sync:prepare-local', [
                'tenant_slug' => 'Demo Instalador',
                'tenant_name' => 'Demo Instalador',
                'email' => 'tecnico.instalador@example.test',
                '--user-name' => 'Tecnico Instalador',
            ])
                ->expectsOutput('Empresa local preparada para sincronizacion.')
                ->expectsOutput('Empresa: demo-instalador')
                ->expectsOutput('Usuario: tecnico.instalador@example.test')
                ->assertExitCode(0);
        } finally {
            putenv('SYNC_BOOTSTRAP_PASSWORD');
        }

        $tenant = Tenant::query()->where('slug', 'demo-instalador')->firstOrFail();
        $user = User::query()->where('email', 'tecnico.instalador@example.test')->firstOrFail();

        $this->assertTrue(Hash::check('clave-local-segura', $user->password));
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        setPermissionsTeamId($tenant->id);

        $this->assertTrue($user->hasRole('Administrador local'));
        $this->assertGreaterThan(0, Role::query()
            ->where('name', 'Administrador local')
            ->where('tenant_id', $tenant->id)
            ->firstOrFail()
            ->permissions()
            ->count());
    }

    public function test_sync_reset_readiness_removes_only_selected_tenant_state(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Empresa Reset A',
            'slug' => 'empresa-reset-a',
        ]);
        $tenantB = Tenant::create([
            'name' => 'Empresa Reset B',
            'slug' => 'empresa-reset-b',
        ]);
        $now = now();

        DB::table('sync_tenant_readiness')->insert([
            [
                'tenant_id' => $tenantA->id,
                'installation_code' => 'LOCAL-PC-RESET',
                'node_code' => 'LOCAL-PC-RESET',
                'node_name' => 'PC Reset',
                'status' => 'ready',
                'metadata' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'tenant_id' => $tenantB->id,
                'installation_code' => 'LOCAL-PC-RESET',
                'node_code' => 'LOCAL-PC-RESET',
                'node_name' => 'PC Reset',
                'status' => 'ready',
                'metadata' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $this->artisan('sync:reset-readiness', [
            'tenant' => $tenantA->slug,
            '--installation' => 'LOCAL-PC-RESET',
        ])
            ->expectsOutput('Estado local de sincronizacion reiniciado.')
            ->expectsOutput('Registros eliminados: 1')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('sync_tenant_readiness', [
            'tenant_id' => $tenantA->id,
            'installation_code' => 'LOCAL-PC-RESET',
        ]);
        $this->assertDatabaseHas('sync_tenant_readiness', [
            'tenant_id' => $tenantB->id,
            'installation_code' => 'LOCAL-PC-RESET',
            'status' => 'ready',
        ]);
    }

    public function test_sync_daemon_can_run_one_controlled_cycle(): void
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Sync Daemon',
            'slug' => 'empresa-sync-daemon',
        ]);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, ['status' => 'active']);

        $eventUuid = (string) Str::uuid();
        $now = now();

        DB::table('sync_outbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'target_scope' => 'tenant',
            'event_type' => 'pos.order.paid',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => 22,
            'payload' => json_encode(['order_id' => 22, 'total_base_amount' => '50.0000']),
            'occurred_at' => $now,
            'available_at' => $now,
            'status' => 'pending',
            'idempotency_key' => 'pos-order-22',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Http::fake([
            'https://cloud.test/api/sync/nodes' => Http::response([
                'data' => ['code' => 'LOCAL-DAEMON-01'],
            ], 201),
            'https://cloud.test/api/sync/events/push' => Http::response([
                'data' => ['received' => 1, 'duplicated' => 0],
            ], 202),
            'https://cloud.test/api/sync/events/pull*' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $this->artisan('sync:daemon', [
            'tenant' => $tenant->slug,
            '--node' => 'LOCAL-DAEMON-01',
            '--name' => 'Local Daemon 01',
            '--cloud-url' => 'https://cloud.test/api',
            '--token' => 'token-demo',
            '--limit' => 10,
            '--once' => true,
            '--installation' => 'LOCAL-DAEMON-PC-01',
        ])
            ->expectsOutput('Worker continuo de sincronizacion iniciado.')
            ->expectsOutput('Worker continuo detenido por limite de ciclos.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'status' => 'processed',
        ]);
        $this->assertDatabaseHas('sync_nodes', [
            'tenant_id' => $tenant->id,
            'code' => 'LOCAL-DAEMON-01',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('sync_tenant_readiness', [
            'tenant_id' => $tenant->id,
            'installation_code' => 'LOCAL-DAEMON-PC-01',
            'node_code' => 'LOCAL-DAEMON-01',
            'status' => 'ready',
        ]);

        Http::assertSentCount(3);
    }
}
