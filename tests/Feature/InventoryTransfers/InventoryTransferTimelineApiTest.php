<?php

namespace Tests\Feature\InventoryTransfers;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FASE T2: tests del endpoint de timeline
 * `GET /api/inventory-transfers/{id}/timeline` y de los filtros
 * adicionales en `GET /api/inventory-transfers`
 * (from_warehouse_id, to_warehouse_id, date_from, date_to).
 */
class InventoryTransferTimelineApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    /**
     * Crea tenant + user + token + branch + 2 warehouses + product simple
     * con stock disponible en el from-warehouse. Devuelve todo lo necesario
     * para los tests.
     */
    private function bootstrap(): array
    {
        $tenant = Tenant::create(['name' => 'T1', 'slug' => 't1', 'is_group' => true]);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::factory()->create(['email' => 'a@t.test', 'password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $role = Role::create(['name' => 'Admin', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
        $role->givePermissionTo('inventory_transfers.view');
        $role->givePermissionTo('inventory_transfers.create');
        $role->givePermissionTo('inventory_transfers.prepare');
        $role->givePermissionTo('inventory_transfers.dispatch');
        $role->givePermissionTo('inventory_transfers.receive');
        $role->givePermissionTo('inventory_transfers.cancel');
        $role->givePermissionTo('inventory_transfers.resolve_differences');
        $user->assignRole($role);

        $branch = Branch::create(['name' => 'B1', 'code' => 'B1-' . uniqid(), 'status' => 'active']);
        $from = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'W1',
            'code' => 'W1-' . uniqid(),
            'status' => 'active',
        ]);
        $to = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'W2',
            'code' => 'W2-' . uniqid(),
            'status' => 'active',
        ]);

        $product = Product::create([
            'name' => 'Prod Test',
            'sku' => 'SKU-' . uniqid(),
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'is_active' => true,
            'tenant_id' => $tenant->id,
        ]);

        DB::table('stock_balances')->insert([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $from->id,
            'product_id' => $product->id,
            'quantity_available' => 100,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
            'updated_at' => now(),
        ]);

        $token = Str::random(80);
        DB::table('auth_tokens')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($tenant->id);

        return [$tenant, $user, $token, $from, $to, $product];
    }

    public function test_timeline_returns_events_for_completed_transfer(): void
    {
        [$tenant, $user, $token, $from, $to, $product] = $this->bootstrap();

        $created = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'validation_mode' => 'logistics',
                'reason' => 'Test timeline',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 5,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$created}/prepare", [
                'items' => [[
                    'inventory_transfer_item_id' => DB::table('inventory_transfer_items')->where('inventory_transfer_id', $created)->value('id'),
                    'prepared_quantity' => 5,
                ]],
            ])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$created}/dispatch")
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$created}/receive", [
                'items' => [[
                    'inventory_transfer_item_id' => DB::table('inventory_transfer_items')->where('inventory_transfer_id', $created)->value('id'),
                    'received_quantity' => 5,
                ]],
            ])
            ->assertOk();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-transfers/{$created}/timeline")
            ->assertOk()
            ->json();

        $stages = array_column($response['data'], 'stage');
        $this->assertContains('created', $stages);
        $this->assertContains('prepared', $stages);
        $this->assertContains('dispatched', $stages);
        $this->assertContains('received', $stages);

        $timestamps = array_column($response['data'], 'at');
        $sorted = $timestamps;
        sort($sorted);
        $this->assertSame($sorted, $timestamps, 'Los eventos deben estar en orden ascendente.');
    }

    public function test_timeline_includes_cancelled_event_when_cancelled(): void
    {
        [$tenant, $user, $token, $from, $to, $product] = $this->bootstrap();

        $created = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'validation_mode' => 'logistics',
                'reason' => 'Test cancel',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 5,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$created}/cancel", [
                'cancellation_reason' => 'No se necesita mas',
            ])
            ->assertOk();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-transfers/{$created}/timeline")
            ->assertOk()
            ->json();

        $stages = array_column($response['data'], 'stage');
        $this->assertContains('created', $stages);
        $this->assertContains('cancelled', $stages);
    }

    public function test_timeline_requires_view_permission(): void
    {
        [$tenant, $user, $token, $from, $to, $product] = $this->bootstrap();

        $created = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'validation_mode' => 'logistics',
                'reason' => 'Test',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 5,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $token2 = Str::random(80);
        $other = User::factory()->create();
        $other->tenants()->attach($tenant, ['status' => 'active']);
        DB::table('auth_tokens')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $other->id,
            'name' => 'test2',
            'token_hash' => hash('sha256', $token2),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token2)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-transfers/{$created}/timeline")
            ->assertStatus(403);
    }

    public function test_index_filters_by_from_warehouse_id(): void
    {
        [$tenant, $user, $token, $from, $to, $product] = $this->bootstrap();
        $branch = Branch::where('id', '!=', null)->first();
        $other = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'W3',
            'code' => 'W3-' . uniqid(),
            'status' => 'active',
        ]);

        DB::table('stock_balances')->insert([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $other->id,
            'product_id' => $product->id,
            'quantity_available' => 5,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
            'updated_at' => now(),
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->withHeader('X-Tenant', $tenant->slug)
                ->postJson('/api/inventory-transfers', [
                    'from_warehouse_id' => $from->id,
                    'to_warehouse_id' => $to->id,
                    'reason' => "T{$i}",
                    'items' => [['product_id' => $product->id, 'quantity' => 1]],
                ])->assertCreated();
        }

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $other->id,
                'to_warehouse_id' => $to->id,
                'reason' => 'Otro',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-transfers?from_warehouse_id={$from->id}")
            ->assertOk()
            ->json();

        $fromIds = array_unique(array_column($response['data'], 'from_warehouse_id'));
        $this->assertCount(1, $fromIds);
        $this->assertSame((int) $from->id, (int) $fromIds[0]);
        $this->assertGreaterThanOrEqual(3, count($response['data']));
    }

    public function test_index_filters_by_to_warehouse_id(): void
    {
        [$tenant, $user, $token, $from, $to, $product] = $this->bootstrap();
        $branch = Branch::where('id', '!=', null)->first();
        $other = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'W4',
            'code' => 'W4-' . uniqid(),
            'status' => 'active',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $other->id,
                'reason' => 'Otro destino',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-transfers?to_warehouse_id={$other->id}")
            ->assertOk()
            ->json();

        foreach ($response['data'] as $t) {
            $this->assertSame((int) $other->id, (int) $t['to_warehouse_id']);
        }
    }

    public function test_index_filters_by_date_range(): void
    {
        [$tenant, $user, $token, $from, $to, $product] = $this->bootstrap();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'reason' => 'Hoy',
                'processed_at' => now()->toDateTimeString(),
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertCreated();

        $yesterday = now()->subDay()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-transfers?date_from={$yesterday}&date_to={$tomorrow}")
            ->assertOk()
            ->json();

        $this->assertGreaterThanOrEqual(1, count($response['data']));

        $farFuture = now()->addDays(30)->toDateString();
        $response2 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-transfers?date_from={$farFuture}")
            ->assertOk()
            ->json();

        $this->assertCount(0, $response2['data']);
    }
}
