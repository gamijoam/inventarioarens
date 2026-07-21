<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Products\Models\Product;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Sync\Services\SyncOutboxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use App\Support\Permissions\BasePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SyncCatalogOutboxIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_event_key_is_deterministic_and_no_longer_uses_uuid(): void
    {
        $carbon = \Carbon\CarbonImmutable::createFromTimestamp(1700000000);
        $key1 = SyncCatalogOutboxService::eventKey('product.updated', 'product', 42, $carbon);
        $key2 = SyncCatalogOutboxService::eventKey('product.updated', 'product', 42, $carbon);

        $this->assertSame($key1, $key2, 'Misma entrada debe producir mismo key');
        $this->assertDoesNotMatchRegularExpression(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
            $key1,
            'No debe contener UUID'
        );
    }

    public function test_event_key_changes_when_version_changes(): void
    {
        $carbon1 = \Carbon\CarbonImmutable::createFromTimestampMs(1700000000000);
        $carbon2 = \Carbon\CarbonImmutable::createFromTimestampMs(1700000000001);

        $key1 = SyncCatalogOutboxService::eventKey('product.updated', 'product', 42, $carbon1);
        $key2 = SyncCatalogOutboxService::eventKey('product.updated', 'product', 42, $carbon2);

        $this->assertNotSame($key1, $key2, 'Diferente version debe producir diferente key');
    }

    public function test_event_key_distinguishes_microsecond_differences(): void
    {
        $carbon1 = \Carbon\CarbonImmutable::createFromTimestampMs(1700000000123);
        $carbon2 = \Carbon\CarbonImmutable::createFromTimestampMs(1700000000124);

        $key1 = SyncCatalogOutboxService::eventKey('product.updated', 'product', 42, $carbon1);
        $key2 = SyncCatalogOutboxService::eventKey('product.updated', 'product', 42, $carbon2);

        $this->assertNotSame($key1, $key2, 'Updates en el mismo segundo pero distintos microsegundos deben producir keys distintos');
    }

    public function test_event_key_falls_back_to_zero_when_no_version(): void
    {
        $key = SyncCatalogOutboxService::eventKey('product.created', 'product', 42);

        $this->assertSame('product.created:product:42:0', $key);
    }

    public function test_event_key_accepts_int_version_directly(): void
    {
        $key = SyncCatalogOutboxService::eventKey('product.created', 'product', 42, 1700000000);

        $this->assertSame('product.created:product:42:1700000000', $key);
    }

    public function test_double_record_with_same_aggregate_only_creates_one_outbox_row(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Sync', 'slug' => 'tienda-sync']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        app(TenantManager::class)->set($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'WH-01']);

        $product = Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-SYNC-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        DB::table('sync_nodes')->insert([
            'tenant_id' => $tenant->id,
            'code' => 'local-test-1',
            'name' => 'Local Test 1',
            'type' => 'local',
            'status' => 'active',
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $outbox = app(SyncOutboxService::class);

        $product->refresh();
        $version = $product->updated_at?->getTimestamp();

        $key = SyncCatalogOutboxService::eventKey('product.updated', 'product', $product->id, $version);

        $id1 = $outbox->record(
            eventType: 'product.updated',
            aggregateType: 'product',
            aggregateId: $product->id,
            payload: ['sku' => $product->sku],
            idempotencyKey: $key,
            targetScope: 'tenant',
            targetNodeId: null,
        );

        $countAfterFirst = DB::table('sync_outbox')->where('event_type', 'product.updated')->count();
        $this->assertSame(1, $countAfterFirst, 'Despues del primer record debe haber 1 row');

        $id2 = $outbox->record(
            eventType: 'product.updated',
            aggregateType: 'product',
            aggregateId: $product->id,
            payload: ['sku' => $product->sku],
            idempotencyKey: $key,
            targetScope: 'tenant',
            targetNodeId: null,
        );

        $countAfterSecond = DB::table('sync_outbox')->where('event_type', 'product.updated')->count();
        $this->assertSame(1, $countAfterSecond, 'Segundo record con misma key NO debe crear row adicional');
        $this->assertSame($id1, $id2, 'Debe retornar el mismo id del primer record');

        \Carbon\Carbon::setTestNow(now()->addSecond());
        $product->update(['name' => 'Test Product Renombrado']);
        $product->refresh();
        $newVersion = $product->updated_at?->getTimestamp();
        $newKey = SyncCatalogOutboxService::eventKey('product.updated', 'product', $product->id, $newVersion);

        $this->assertNotSame($key, $newKey, 'Cambio en updated_at debe producir key diferente');

        $outbox->record(
            eventType: 'product.updated',
            aggregateType: 'product',
            aggregateId: $product->id,
            payload: ['sku' => $product->sku, 'name' => $product->name],
            idempotencyKey: $newKey,
            targetScope: 'tenant',
            targetNodeId: null,
        );

        $countAfterUpdate = DB::table('sync_outbox')->where('event_type', 'product.updated')->count();
        $this->assertSame(2, $countAfterUpdate, 'Record con key diferente (nuevo updated_at) SI debe crear row');
    }

    public function test_sync_catalog_service_product_updated_uses_stable_key(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Sync 2', 'slug' => 'tienda-sync-2']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        app(TenantManager::class)->set($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'WH-01']);

        $product = Product::create([
            'name' => 'Producto Sync',
            'sku' => 'PROD-SYNC',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 50,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        $instance = $product;
        app(SyncCatalogOutboxService::class)->productUpdated($instance);
        app(SyncCatalogOutboxService::class)->productUpdated($instance);

        $count = DB::table('sync_outbox')
            ->where('event_type', 'product.updated')
            ->where('aggregate_type', 'product')
            ->where('aggregate_id', $product->id)
            ->count();

        $this->assertSame(1, $count, 'Dos llamadas seguidas con misma fila y mismo payload deben producir 1 sola fila (dedup estable)');

        $keys = DB::table('sync_outbox')
            ->where('event_type', 'product.updated')
            ->where('aggregate_id', $product->id)
            ->pluck('idempotency_key')
            ->all();

        $this->assertCount(1, $keys);
        $key = $keys[0];
        $this->assertDoesNotMatchRegularExpression(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
            $key,
            'Key no debe contener UUID'
        );
        $this->assertMatchesRegularExpression(
            '/^product\.updated:product:\d+:\d+:[0-9a-f]{16}$/',
            $key,
            'Key debe seguir el patron eventType:aggregateType:aggregateId:version:payloadHash. Key: '.$key
        );
    }

    public function test_currency_routes_emit_stable_idempotency_keys(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Sync 3', 'slug' => 'tienda-sync-3']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $role = \Spatie\Permission\Models\Role::findOrCreate('Admin Currency', 'web');
        $role->syncPermissions(['currency.manage']);
        $user->assignRole($role);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'WH-01']);

        DB::table('sync_nodes')->insert([
            'tenant_id' => $tenant->id,
            'code' => 'local-test-3',
            'name' => 'Local Test 3',
            'type' => 'local',
            'status' => 'active',
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rateTypeId = DB::table('exchange_rate_types')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'BCV',
            'name' => 'Tasa BCV',
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rate = \App\Modules\Currency\Models\ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'exchange_rate_type_id' => $rateTypeId,
            'base_currency' => 'USD',
            'quote_currency' => 'VES',
            'rate' => 500,
            'effective_at' => now(),
            'is_active' => true,
            'source' => 'Manual',
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/currency/rates', [
                'exchange_rate_type_id' => $rateTypeId,
                'rate' => 600,
                'effective_at' => now()->addDay()->toISOString(),
                'source' => 'BCV',
            ])
            ->assertCreated();

        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $rate2 = \App\Modules\Currency\Models\ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'exchange_rate_type_id' => $rateTypeId,
            'base_currency' => 'USD',
            'quote_currency' => 'VES',
            'rate' => 700,
            'effective_at' => now()->addDays(2),
            'is_active' => false,
            'source' => 'Manual',
        ]);

        $keys = DB::table('sync_outbox')
            ->where('aggregate_type', 'exchange_rate')
            ->orderBy('id')
            ->pluck('idempotency_key')
            ->all();

        $this->assertNotEmpty($keys, 'Debe haber al menos 1 key en sync_outbox');
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression(
                '/^exchange_rate\.(created|updated):exchange_rate:\d+:\d+:[0-9a-f]{16}(:node:\d+)?$/',
                $key,
                "Key de currency debe seguir patron eventType:aggregateType:aggregateId:version:payloadHash (opcional :node:N). Key: {$key}"
            );
            $lastSegment = substr($key, strrpos($key, ':') + 1);
            $this->assertDoesNotMatchRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
                $lastSegment,
                'Ultimo segmento del key no debe ser UUID. Key: '.$key
            );
        }

        $firstKey = $keys[0];
        $countBeforeRetry = DB::table('sync_outbox')
            ->where('aggregate_type', 'exchange_rate')
            ->count();

        $retryPayload = ['reintento' => true, 'timestamp' => 12345];
        app(\App\Modules\Sync\Services\SyncOutboxService::class)->record(
            eventType: 'exchange_rate.created',
            aggregateType: 'exchange_rate',
            aggregateId: 999,
            payload: $retryPayload,
            idempotencyKey: 'custom:retry:key:xyz',
            targetScope: 'tenant',
            targetNodeId: null,
        );

        $countAfterFirstRetry = DB::table('sync_outbox')
            ->where('aggregate_type', 'exchange_rate')
            ->count();
        $this->assertSame($countBeforeRetry + 1, $countAfterFirstRetry, 'Primer record con key custom debe crear 1 row');

        app(\App\Modules\Sync\Services\SyncOutboxService::class)->record(
            eventType: 'exchange_rate.created',
            aggregateType: 'exchange_rate',
            aggregateId: 999,
            payload: $retryPayload,
            idempotencyKey: 'custom:retry:key:xyz',
            targetScope: 'tenant',
            targetNodeId: null,
        );

        $countAfterSecondRetry = DB::table('sync_outbox')
            ->where('aggregate_type', 'exchange_rate')
            ->count();

        $this->assertSame(
            $countAfterFirstRetry,
            $countAfterSecondRetry,
            'Segundo record con misma key+payload NO debe crear row adicional (dedup OK)'
        );
    }
}
