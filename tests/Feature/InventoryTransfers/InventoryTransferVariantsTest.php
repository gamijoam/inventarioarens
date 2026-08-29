<?php

namespace Tests\Feature\InventoryTransfers;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\InventoryTransfers\Models\InventoryTransferItem;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventoryTransferVariantsTest extends TestCase
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

    public function test_user_can_transfer_variant_quantity_to_another_warehouse(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Variantes', 'slug' => 'empresa-variantes']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-VAR', Product::TRACKING_QUANTITY);
        [$blue, $orange] = $this->variants($tenant, $product, ['Azul', 'Naranja']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen Variante', ['inventory_transfers.create', 'inventory_transfers.view']);

        $this->stockVariant($tenant, $fromWarehouse, $product, $blue, $user, 4);
        $this->stockVariant($tenant, $fromWarehouse, $product, $orange, $user, 5);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'reason' => 'Reposicion con variantes',
                'items' => [
                    ['product_id' => $product->id, 'product_variant_id' => $blue->id, 'quantity' => 2],
                    ['product_id' => $product->id, 'product_variant_id' => $orange->id, 'quantity' => 3],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.product_variant_id', $blue->id)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.1.product_variant_id', $orange->id)
            ->assertJsonPath('data.items.1.quantity', 3);

        $this->assertSame(2.0, (float) $this->balance($fromWarehouse, $product, $blue)->quantity_available);
        $this->assertSame(2.0, (float) $this->balance($fromWarehouse, $product, $orange)->quantity_available);
        $this->assertSame(2.0, (float) $this->balance($toWarehouse, $product, $blue)->quantity_available);
        $this->assertSame(3.0, (float) $this->balance($toWarehouse, $product, $orange)->quantity_available);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $fromWarehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $blue->id,
            'type' => 'transfer_out',
            'quantity' => '2.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $toWarehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $orange->id,
            'type' => 'transfer_in',
            'quantity' => '3.0000',
        ]);

        $transfer = InventoryTransfer::query()->latest('id')->first();
        $this->assertDatabaseHas('inventory_transfer_items', [
            'tenant_id' => $tenant->id,
            'inventory_transfer_id' => $transfer->id,
            'product_id' => $product->id,
            'product_variant_id' => $blue->id,
            'quantity' => '2.0000',
        ]);
    }

    public function test_transfer_with_variant_rejects_insufficient_variant_stock(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Variantes 2', 'slug' => 'empresa-variantes-2']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-VAR2', Product::TRACKING_QUANTITY);
        [$blue] = $this->variants($tenant, $product, ['Azul']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen Variante 2', ['inventory_transfers.create', 'inventory_transfers.view']);

        $this->stockVariant($tenant, $fromWarehouse, $product, $blue, $user, 4);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'reason' => 'Sin stock',
                'items' => [
                    ['product_id' => $product->id, 'product_variant_id' => $blue->id, 'quantity' => 6],
                ],
            ])
            ->assertUnprocessable();

        $this->assertSame(4.0, (float) $this->balance($fromWarehouse, $product, $blue)->quantity_available);
        $this->assertNull($this->balanceOrNull($toWarehouse, $product, $blue));
    }

    public function test_transfer_rejects_variant_not_belonging_to_product(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Variantes 3', 'slug' => 'empresa-variantes-3']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-VAR3', Product::TRACKING_QUANTITY);
        [, , $otherProduct] = $this->warehousesAndProduct($tenant, 'TRF-VAR3B', Product::TRACKING_QUANTITY);
        [$foreignVariant] = $this->variants($tenant, $otherProduct, ['Rojo']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen Variante 3', ['inventory_transfers.create', 'inventory_transfers.view']);

        $this->stock($tenant, $fromWarehouse, $product, $user, 10);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'reason' => 'Variante ajena',
                'items' => [
                    ['product_id' => $product->id, 'product_variant_id' => $foreignVariant->id, 'quantity' => 2],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_variant_id']);
    }

    public function test_logistics_transfer_moves_variant_stock_on_prepare_dispatch_receive(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Variantes Log', 'slug' => 'empresa-variantes-log']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-VARLOG', Product::TRACKING_QUANTITY);
        [$blue] = $this->variants($tenant, $product, ['Azul']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen Variante Log', [
            'inventory_transfers.create',
            'inventory_transfers.view',
            'inventory_transfers.prepare',
            'inventory_transfers.dispatch',
            'inventory_transfers.receive',
        ]);

        $this->stockVariant($tenant, $fromWarehouse, $product, $blue, $user, 10);

        $transferId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'validation_mode' => InventoryTransfer::VALIDATION_LOGISTICS,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'reason' => 'Guia con variantes',
                'items' => [
                    ['product_id' => $product->id, 'product_variant_id' => $blue->id, 'quantity' => 4],
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $itemId = InventoryTransferItem::query()->where('inventory_transfer_id', $transferId)->firstOrFail()->id;

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/prepare", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'prepared_quantity' => 4,
                ]],
            ])
            ->assertOk();

        $blueBalance = $this->balance($fromWarehouse, $product, $blue);
        $this->assertSame(6.0, (float) $blueBalance->quantity_available);
        $this->assertSame(4.0, (float) $blueBalance->quantity_reserved);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/dispatch")
            ->assertOk();

        $blueBalance->refresh();
        $this->assertSame(6.0, (float) $blueBalance->quantity_available);
        $this->assertSame(0.0, (float) $blueBalance->quantity_reserved);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $fromWarehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $blue->id,
            'type' => 'transfer_out',
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/inventory-transfers/{$transferId}/receive", [
                'items' => [[
                    'inventory_transfer_item_id' => $itemId,
                    'received_quantity' => 4,
                ]],
            ])
            ->assertOk();

        $this->assertSame(4.0, (float) $this->balance($toWarehouse, $product, $blue)->quantity_available);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $toWarehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $blue->id,
            'type' => 'transfer_in',
        ]);
    }

    public function test_transfer_without_variant_keeps_legacy_behavior(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Legacy', 'slug' => 'empresa-legacy']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-LEGACY', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen Legacy', ['inventory_transfers.create', 'inventory_transfers.view']);

        $this->stock($tenant, $fromWarehouse, $product, $user, 10);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'reason' => 'Sin variante',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 4],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.product_variant_id', null);

        $this->assertSame(6.0, (float) $this->balance($fromWarehouse, $product, null)->quantity_available);
        $this->assertSame(4.0, (float) $this->balance($toWarehouse, $product, null)->quantity_available);
    }

    private function warehousesAndProduct(Tenant $tenant, string $sku, string $trackingType): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal {$sku}", 'code' => "BR-{$sku}"]);
        $fromWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Origen {$sku}", 'code' => "FROM-{$sku}"]);
        $toWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Destino {$sku}", 'code' => "TO-{$sku}"]);
        $product = Product::create([
            'name' => "Producto {$sku}",
            'sku' => $sku,
            'tracking_type' => $trackingType,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        return [$fromWarehouse, $toWarehouse, $product];
    }

    private function variants(Tenant $tenant, Product $product, array $colors): array
    {
        $this->useTenant($tenant);

        return array_map(fn (string $color, int $index): ProductVariant => ProductVariant::create([
            'product_id' => $product->id,
            'color' => $color,
            'position' => $index,
        ]), $colors, array_keys($colors));
    }

    private function stock(Tenant $tenant, Warehouse $warehouse, Product $product, User $user, float $quantity): void
    {
        $this->useTenant($tenant);

        app(InventoryMovementService::class)->purchase(
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            unitCost: 50,
            createdBy: $user,
            reason: "Stock prueba {$product->sku}",
        );
    }

    private function stockVariant(Tenant $tenant, Warehouse $warehouse, Product $product, ProductVariant $variant, User $user, float $quantity): void
    {
        $this->useTenant($tenant);

        app(InventoryMovementService::class)->purchase(
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            unitCost: 50,
            createdBy: $user,
            productVariantId: $variant->id,
            reason: "Stock variante {$variant->color}",
        );
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): void
    {
        $this->useTenant($tenant);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function balance(Warehouse $warehouse, Product $product, ?ProductVariant $variant = null): StockBalance
    {
        return StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->firstOrFail();
    }

    private function balanceOrNull(Warehouse $warehouse, Product $product, ?ProductVariant $variant = null): ?StockBalance
    {
        return StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->first();
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
