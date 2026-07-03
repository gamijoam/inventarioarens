<?php

namespace Tests\Feature\ProductExits;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\ProductExits\Models\ProductExit;
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

class ProductExitApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_quantity_product_exit(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'EXIT-AUD', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['product_exits.create', 'product_exits.view']);
        $this->stock($tenant, $warehouse, $product, $user, 10);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/product-exits', [
                'reason' => ProductExit::REASON_INTERNAL_USE,
                'reference' => 'USO-001',
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 3,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.document_number', 'SAL-000001')
            ->assertJsonPath('data.items.0.quantity', '3.0000');

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '7.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'adjustment_out',
            'quantity' => '3.0000',
            'reference_type' => ProductExit::class,
        ]);
    }

    public function test_damaged_exit_moves_quantity_to_damaged_bucket(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'EXIT-DMG', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['product_exits.create']);
        $this->stock($tenant, $warehouse, $product, $user, 5);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/product-exits', [
                'reason' => ProductExit::REASON_DAMAGED,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                ]],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '3.0000',
            'quantity_damaged' => '2.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'type' => 'damaged',
        ]);
    }

    public function test_user_can_create_serialized_product_exit_with_specific_imeis(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'EXIT-SAM', Product::TRACKING_SERIALIZED);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['product_exits.create', 'product_exits.view']);
        $movement = $this->stock($tenant, $warehouse, $product, $user, 3);
        $units = $this->units($tenant, $warehouse, $product, $movement->id, '860777', 3);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/product-exits', [
                'reason' => ProductExit::REASON_WARRANTY,
                'reference' => 'GAR-001',
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'product_unit_ids' => [$units[0]->id, $units[1]->id],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.product_unit_ids.1', $units[1]->id);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/product-exits?search=000000002&warehouse_id='.$warehouse->id.'&date_from='.now()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.created_by_user.email', $user->email)
            ->assertJsonPath('data.0.items.0.serial_units.0.serial_number', '860777000000001')
            ->assertJsonPath('data.0.items.0.serial_units.1.serial_number', '860777000000002');

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[0]->id,
            'status' => ProductUnit::STATUS_REMOVED,
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $units[2]->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '1.0000',
        ]);
    }

    public function test_serialized_exit_rejects_unavailable_or_wrong_units(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'EXIT-BLOCK', Product::TRACKING_SERIALIZED);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['product_exits.create']);
        $movement = $this->stock($tenant, $warehouse, $product, $user, 1);
        $unit = $this->units($tenant, $warehouse, $product, $movement->id, '860778', 1)[0];
        $unit->update(['status' => ProductUnit::STATUS_SOLD]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/product-exits', [
                'reason' => ProductExit::REASON_LOST,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'product_unit_ids' => [$unit->id],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_unit_ids.0']);
    }

    public function test_product_exit_rejects_more_than_available_quantity(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'EXIT-OVER', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Almacen A', ['product_exits.create']);
        $this->stock($tenant, $warehouse, $product, $user, 1);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/product-exits', [
                'reason' => ProductExit::REASON_LOST,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                ]],
            ])
            ->assertUnprocessable();
    }

    public function test_product_exits_do_not_mix_companies_and_reject_foreign_resources(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        [$warehouseA, $productA] = $this->warehouseAndProduct($tenantA, 'PEX-A', Product::TRACKING_QUANTITY);
        [$warehouseB, $productB] = $this->warehouseAndProduct($tenantB, 'PEX-B', Product::TRACKING_QUANTITY);
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantA, $userA, 'Almacen A', ['product_exits.create', 'product_exits.view']);
        $this->grantRole($tenantB, $userB, 'Almacen B', ['product_exits.create', 'product_exits.view']);
        $this->stock($tenantA, $warehouseA, $productA, $userA, 2);
        $this->stock($tenantB, $warehouseB, $productB, $userB, 2);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/product-exits', [
                'reason' => ProductExit::REASON_INTERNAL_USE,
                'items' => [[
                    'warehouse_id' => $warehouseA->id,
                    'product_id' => $productA->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated();

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->postJson('/api/product-exits', [
                'reason' => ProductExit::REASON_LOST,
                'items' => [[
                    'warehouse_id' => $warehouseB->id,
                    'product_id' => $productB->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated();

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/product-exits')
            ->assertOk()
            ->assertJsonPath('data.0.reason', ProductExit::REASON_INTERNAL_USE)
            ->assertJsonPath('data.0.items.0.product.sku', 'PEX-A')
            ->assertJsonPath('data.0.items.0.warehouse.code', 'WH-PEX-A')
            ->assertJsonMissing(['reason' => ProductExit::REASON_LOST]);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/product-exits', [
                'reason' => ProductExit::REASON_OTHER,
                'items' => [[
                    'warehouse_id' => $warehouseB->id,
                    'product_id' => $productA->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.warehouse_id']);
    }

    public function test_product_exit_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->warehouseAndProduct($tenant, 'PEX-NOAUTH', Product::TRACKING_QUANTITY);
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/product-exits', [
                'reason' => ProductExit::REASON_OTHER,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
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

    private function warehouseAndProduct(Tenant $tenant, string $sku, string $trackingType): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal {$sku}", 'code' => "BR-{$sku}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Almacen {$sku}", 'code' => "WH-{$sku}"]);
        $product = Product::create([
            'name' => "Producto {$sku}",
            'sku' => $sku,
            'tracking_type' => $trackingType,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        return [$warehouse, $product];
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

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
