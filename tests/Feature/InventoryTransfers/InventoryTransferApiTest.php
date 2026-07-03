<?php

namespace Tests\Feature\InventoryTransfers;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventoryTransferApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_quantity_inventory_transfer(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-QTY', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['inventory_transfers.create', 'inventory_transfers.view']);
        $this->stock($tenant, $fromWarehouse, $product, $user, 10);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'reason' => 'Reposicion de sucursal',
                'reference' => 'TRAS-001',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 4,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.document_number', 'TRF-000001')
            ->assertJsonPath('data.type', InventoryTransfer::TYPE_INTERNAL)
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_COMPLETED)
            ->assertJsonPath('data.items.0.quantity', 4);

        $this->assertSame(6.0, (float) $this->balance($fromWarehouse, $product)->quantity_available);
        $this->assertSame(4.0, (float) $this->balance($toWarehouse, $product)->quantity_available);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $fromWarehouse->id,
            'product_id' => $product->id,
            'type' => 'transfer_out',
            'quantity' => '4.0000',
            'reference_type' => InventoryTransfer::class,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $toWarehouse->id,
            'product_id' => $product->id,
            'type' => 'transfer_in',
            'quantity' => '4.0000',
            'reference_type' => InventoryTransfer::class,
        ]);
    }

    public function test_user_can_transfer_serialized_product_units_to_another_warehouse(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-IMEI', Product::TRACKING_SERIALIZED);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['inventory_transfers.create', 'inventory_transfers.view']);
        $movement = $this->stock($tenant, $fromWarehouse, $product, $user, 3);
        $units = $this->units($tenant, $fromWarehouse, $product, $movement->id, '861000', 3);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'product_unit_ids' => [$units[0]->id, $units[1]->id],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.product_unit_ids.1', $units[1]->id);

        $this->assertSame(1.0, (float) $this->balance($fromWarehouse, $product)->quantity_available);
        $this->assertSame(2.0, (float) $this->balance($toWarehouse, $product)->quantity_available);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[0]->id,
            'warehouse_id' => $toWarehouse->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[2]->id,
            'warehouse_id' => $fromWarehouse->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
    }

    public function test_serialized_transfer_rejects_wrong_or_unavailable_units(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-BLOCK', Product::TRACKING_SERIALIZED);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['inventory_transfers.create']);
        $movement = $this->stock($tenant, $fromWarehouse, $product, $user, 1);
        $unit = $this->units($tenant, $fromWarehouse, $product, $movement->id, '861001', 1)[0];
        $unit->update(['status' => ProductUnit::STATUS_SOLD]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'product_unit_ids' => [$unit->id],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_unit_ids.0']);
    }

    public function test_transfer_rejects_more_than_available_quantity(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-OVER', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['inventory_transfers.create']);
        $this->stock($tenant, $fromWarehouse, $product, $user, 1);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                ]],
            ])
            ->assertUnprocessable();
    }

    public function test_transfers_do_not_mix_companies_and_reject_foreign_resources(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        [$fromA, $toA, $productA] = $this->warehousesAndProduct($tenantA, 'TRF-A', Product::TRACKING_QUANTITY);
        [$fromB, $toB, $productB] = $this->warehousesAndProduct($tenantB, 'TRF-B', Product::TRACKING_QUANTITY);
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantA, $userA, 'Almacen A', ['inventory_transfers.create', 'inventory_transfers.view']);
        $this->grantRole($tenantB, $userB, 'Almacen B', ['inventory_transfers.create', 'inventory_transfers.view']);
        $this->stock($tenantA, $fromA, $productA, $userA, 2);
        $this->stock($tenantB, $fromB, $productB, $userB, 2);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromA->id,
                'to_warehouse_id' => $toA->id,
                'reason' => 'A',
                'items' => [[
                    'product_id' => $productA->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated();

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromB->id,
                'to_warehouse_id' => $toB->id,
                'reason' => 'B',
                'items' => [[
                    'product_id' => $productB->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated();

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/inventory-transfers')
            ->assertOk()
            ->assertJsonPath('data.0.reason', 'A')
            ->assertJsonMissing(['reason' => 'B']);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromA->id,
                'to_warehouse_id' => $toB->id,
                'items' => [[
                    'product_id' => $productA->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to_warehouse_id']);
    }

    public function test_transfer_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$fromWarehouse, $toWarehouse, $product] = $this->warehousesAndProduct($tenant, 'TRF-NOAUTH', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-transfers', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertForbidden();
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
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

    private function stock(Tenant $tenant, Warehouse $warehouse, Product $product, User $user, float $quantity)
    {
        $this->useTenant($tenant);

        return app(InventoryMovementService::class)->purchase(
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            unitCost: 50,
            createdBy: $user,
            reason: "Stock prueba {$product->sku}",
        );
    }

    private function units(Tenant $tenant, Warehouse $warehouse, Product $product, int $movementId, string $prefix, int $quantity): array
    {
        $this->useTenant($tenant);
        $units = [];

        foreach (range(1, $quantity) as $index) {
            $units[] = ProductUnit::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
                'serial_number' => $prefix.str_pad((string) $index, 9, '0', STR_PAD_LEFT),
                'status' => ProductUnit::STATUS_AVAILABLE,
                'acquired_stock_movement_id' => $movementId,
            ]);
        }

        return $units;
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

    private function balance(Warehouse $warehouse, Product $product): StockBalance
    {
        return StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
