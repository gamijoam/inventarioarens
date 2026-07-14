<?php

namespace Tests\Feature\InventoryCenter;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventoryAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('inventory.view', 'web');
        Permission::findOrCreate('products.view', 'web');
    }

    private function setupTenant(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@t.test',
            'password' => bcrypt('secret123'),
        ]);
        $user->tenants()->attach($tenant->id, ['status' => 'active']);
        $user->givePermissionTo(['inventory.view', 'products.view']);

        $branch = Branch::create(['name' => 'B', 'code' => 'B1']);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'W1',
            'code' => 'W1',
        ]);

        return [$tenant, $user, $warehouse];
    }

    public function test_stock_status_out_when_no_stock(): void
    {
        [$tenant, $user, $warehouse] = $this->setupTenant();
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'P',
            'sku' => 'P-1',
            'tracking_type' => 'quantity',
            'min_stock' => 10,
            'max_stock' => 100,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/products/{$product->id}/stock-status");

        $response->assertOk()
            ->assertJsonPath('data.status', 'out')
            ->assertJsonPath('data.status_label', 'Sin stock')
            ->assertJsonPath('data.available', 0)
            ->assertJsonPath('data.suggested_purchase', 100);
    }

    public function test_stock_status_critical_when_below_half_min(): void
    {
        [$tenant, $user, $warehouse] = $this->setupTenant();
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'P',
            'sku' => 'P-1',
            'tracking_type' => 'quantity',
            'min_stock' => 10,
            'max_stock' => 100,
        ]);

        StockBalance::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 3,
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/products/{$product->id}/stock-status");

        $response->assertOk()
            ->assertJsonPath('data.status', 'critical');
    }

    public function test_stock_status_low_when_between_half_and_min(): void
    {
        [$tenant, $user, $warehouse] = $this->setupTenant();
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'P',
            'sku' => 'P-1',
            'tracking_type' => 'quantity',
            'min_stock' => 10,
        ]);

        StockBalance::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 7,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/products/{$product->id}/stock-status");

        $response->assertOk()
            ->assertJsonPath('data.status', 'low');
    }

    public function test_stock_status_overstock(): void
    {
        [$tenant, $user, $warehouse] = $this->setupTenant();
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'P',
            'sku' => 'P-1',
            'tracking_type' => 'quantity',
            'max_stock' => 10,
        ]);

        StockBalance::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 50,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/products/{$product->id}/stock-status");

        $response->assertOk()
            ->assertJsonPath('data.status', 'overstock');
    }

    public function test_reorder_suggestions_returns_only_below_min(): void
    {
        [$tenant, $user, $warehouse] = $this->setupTenant();

        $p1 = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Low', 'sku' => 'L-1',
            'tracking_type' => 'quantity', 'min_stock' => 10, 'reorder_quantity' => 50,
        ]);
        StockBalance::create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $p1->id,
            'quantity_available' => 3,
        ]);

        $p2 = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'OK', 'sku' => 'O-1',
            'tracking_type' => 'quantity', 'min_stock' => 5,
        ]);
        StockBalance::create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $p2->id,
            'quantity_available' => 100,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/reorder-suggestions');

        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertSame('Low', $data[0]['product_name']);
        $this->assertSame(50, $data[0]['suggested_purchase']);
        $this->assertSame('critical', $data[0]['status']);
        $this->assertSame(1, $response->json('data.summary.total_suggestions'));
    }

    public function test_reorder_suggestions_sorts_most_critical_first(): void
    {
        [$tenant, $user, $warehouse] = $this->setupTenant();

        $p1 = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Out', 'sku' => 'O-1',
            'tracking_type' => 'quantity', 'min_stock' => 10,
        ]);
        StockBalance::create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $p1->id,
            'quantity_available' => 0,
        ]);

        $p2 = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Low', 'sku' => 'L-1',
            'tracking_type' => 'quantity', 'min_stock' => 10,
        ]);
        StockBalance::create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $p2->id,
            'quantity_available' => 5,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/reorder-suggestions');

        $data = $response->json('data.data');
        $this->assertSame('Out', $data[0]['product_name']);
    }

    public function test_alerts_summary_counts_below_min(): void
    {
        [$tenant, $user, $warehouse] = $this->setupTenant();

        $p1 = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'A', 'sku' => 'A-1',
            'tracking_type' => 'quantity', 'min_stock' => 10,
        ]);
        StockBalance::create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $p1->id,
            'quantity_available' => 2,
        ]);

        $p2 = Product::create([
            'tenant_id' => $tenant->id, 'name' => 'B', 'sku' => 'B-1',
            'tracking_type' => 'quantity', 'min_stock' => 5,
        ]);
        StockBalance::create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $p2->id,
            'quantity_available' => 0,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/alerts-summary');

        $response->assertOk()
            ->assertJsonPath('data.low_count', 1)
            ->assertJsonPath('data.out_count', 1)
            ->assertJsonPath('data.with_min_stock_count', 2);
    }
}
