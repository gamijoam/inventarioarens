<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockCountApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('inventory.view', 'web');
        Permission::findOrCreate('inventory.adjust', 'web');
    }

    private function bootstrap(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $branch = Branch::create(['name' => 'B', 'code' => 'B1']);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id, 'name' => 'W1', 'code' => 'W1',
        ]);

        $product = Product::create([
            'name' => 'P', 'sku' => 'P-1',
            'tracking_type' => 'quantity',
        ]);

        $user = User::create([
            'name' => 'A', 'email' => 'a@t.test', 'password' => bcrypt('secret'),
        ]);
        $user->tenants()->attach($tenant->id, ['status' => 'active']);
        $user->givePermissionTo(['inventory.view', 'inventory.adjust']);

        return [$tenant, $warehouse, $product, $user];
    }

    public function test_can_create_stock_count(): void
    {
        [$tenant, $warehouse, , $user] = $this->bootstrap();

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/stock-counts', [
                'warehouse_id' => $warehouse->id,
                'code' => 'CC-2026-001',
                'name' => 'Conteo completo Q3',
                'count_type' => 'full',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.code', 'CC-2026-001')
            ->assertJsonPath('data.created_by', $user->id);
    }

    public function test_snapshot_creates_items_from_existing_balances(): void
    {
        [$tenant, $warehouse, $product, $user] = $this->bootstrap();
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 12,
            'quantity_reserved' => 1,
            'quantity_damaged' => 0,
        ]);

        $count = StockCount::create([
            'warehouse_id' => $warehouse->id,
            'code' => 'CC-001',
            'name' => 'Test',
            'count_type' => 'full',
            'created_by' => $user->id,
        ]);

        $counted = app(StockCountService::class)->snapshot($count);

        $this->assertSame(1, $counted);
        $this->assertSame(1, $count->items()->count());
        $this->assertEquals(12, (float) $count->items()->first()->system_quantity);
    }

    public function test_full_flow_creates_adjustment_movements(): void
    {
        [$tenant, $warehouse, $product, $user] = $this->bootstrap();
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 10,
        ]);

        $service = app(StockCountService::class);
        $count = $service->create($tenant, $warehouse, [
            'code' => 'CC-2026', 'name' => 'Test', 'count_type' => 'full',
        ], $user->id);

        $service->snapshot($count);
        $service->start($count);

        $items = $count->items()->get();
        $this->assertCount(1, $items);

        $service->capture($count, [$items[0]->id => 12], $user->id);

        $result = $service->complete($count, $user->id);

        $this->assertSame(1, $result['in']);
        $this->assertSame(0, $result['out']);

        $movement = StockMovement::where('reference_type', 'stock_count')
            ->where('reference_id', $count->id)
            ->firstOrFail();
        $this->assertSame('adjustment_in', $movement->type);
        $this->assertEquals(2.0, (float) $movement->quantity);
        $this->assertSame("Cycle count {$count->code}", $movement->reason);
    }

    public function test_capture_marks_items_as_counted(): void
    {
        [$tenant, $warehouse, $product, $user] = $this->bootstrap();
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 5,
        ]);

        $service = app(StockCountService::class);
        $count = $service->create($tenant, $warehouse, [
            'code' => 'CC-2026', 'name' => 'Test', 'count_type' => 'full',
        ], $user->id);
        $service->snapshot($count);
        $service->start($count);

        $item = $count->items()->firstOrFail();
        $this->assertSame(StockCountItem::STATUS_PENDING, $item->status);

        $service->capture($count, [$item->id => 5], $user->id);

        $item->refresh();
        $this->assertSame(StockCountItem::STATUS_COUNTED, $item->status);
        $this->assertEquals(0, (float) $item->variance);
    }

    public function test_cannot_complete_count_in_draft_status(): void
    {
        [$tenant, $warehouse, , $user] = $this->bootstrap();

        $service = app(StockCountService::class);
        $count = $service->create($tenant, $warehouse, [
            'code' => 'CC-2026', 'name' => 'Test', 'count_type' => 'full',
        ], $user->id);

        $this->expectException(\RuntimeException::class);
        $service->complete($count, $user->id);
    }

    public function test_api_lifecycle(): void
    {
        [$tenant, $warehouse, , $user] = $this->bootstrap();

        $create = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/stock-counts', [
                'warehouse_id' => $warehouse->id,
                'code' => 'CC-API-001',
                'name' => 'API test',
            ])
            ->assertCreated();
        $countId = $create->json('data.id');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/stock-counts/{$countId}/snapshot")
            ->assertOk()
            ->assertJsonPath('data.items_created', 0);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/stock-counts/{$countId}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'capturing');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/stock-counts/{$countId}/complete", [
                'captures' => [],
            ])
            ->assertOk()
            ->assertJsonPath('data.adjustments.in', 0);
    }
}
