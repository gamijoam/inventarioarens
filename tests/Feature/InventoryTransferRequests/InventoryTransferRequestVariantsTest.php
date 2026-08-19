<?php

namespace Tests\Feature\InventoryTransferRequests;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest;
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

class InventoryTransferRequestVariantsTest extends TestCase
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

    public function test_origin_requests_variant_and_destination_ships_variant_stock(): void
    {
        $originTenant = Tenant::create(['name' => 'Origen Variante', 'slug' => 'origen-variante']);
        $destinationTenant = Tenant::create(['name' => 'Destino Variante', 'slug' => 'destino-variante']);
        [$originWarehouse, $originProduct, $originVariant] = $this->warehouseProductVariant($originTenant, 'TREQ-VAR-O');
        [$destinationWarehouse, $destinationProduct, $destinationVariant] = $this->warehouseProductVariant($destinationTenant, 'TREQ-VAR-D');
        $originUser = $this->userInTenant($originTenant);
        $destinationUser = $this->userInTenant($destinationTenant);
        $this->grantRole($originTenant, $originUser, 'Gerente Var Origen', ['inventory_transfer_requests.create', 'inventory_transfer_requests.view']);
        $this->grantRole($destinationTenant, $destinationUser, 'Gerente Var Destino', ['inventory_transfer_requests.respond', 'inventory_transfer_requests.view']);
        $this->stockVariant($destinationTenant, $destinationWarehouse, $destinationProduct, $destinationVariant, $destinationUser, 6);

        $createResponse = $this
            ->actingAs($originUser)
            ->withHeader('X-Tenant', $originTenant->slug)
            ->postJson('/api/inventory-transfer-requests', [
                'destination_tenant_slug' => $destinationTenant->slug,
                'from_warehouse_id' => $originWarehouse->id,
                'reason' => 'Pedido de variante Azul',
                'items' => [[
                    'product_id' => $originProduct->id,
                    'product_variant_id' => $originVariant->id,
                    'quantity' => 4,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', InventoryTransferRequest::STATUS_REQUESTED)
            ->assertJsonPath('data.items.0.product_variant_id', $originVariant->id);

        $requestId = $createResponse->json('data.id');
        $requestItemId = $createResponse->json('data.items.0.id');

        $this
            ->actingAs($destinationUser)
            ->withHeader('X-Tenant', $destinationTenant->slug)
            ->postJson("/api/inventory-transfer-requests/{$requestId}/accept", [
                'destination_warehouse_id' => $destinationWarehouse->id,
                'items' => [[
                    'request_item_id' => $requestItemId,
                    'destination_product_id' => $destinationProduct->id,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransferRequest::STATUS_COMPLETED);

        $this->useTenant($originTenant);
        $this->assertSame(4.0, (float) $this->balance($originWarehouse, $originProduct, $originVariant)->quantity_available);
        $this->useTenant($destinationTenant);
        $this->assertSame(2.0, (float) $this->balance($destinationWarehouse, $destinationProduct, $destinationVariant)->quantity_available);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $destinationTenant->id,
            'warehouse_id' => $destinationWarehouse->id,
            'product_id' => $destinationProduct->id,
            'product_variant_id' => $destinationVariant->id,
            'type' => 'transfer_request_out',
            'quantity' => '4.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $originTenant->id,
            'warehouse_id' => $originWarehouse->id,
            'product_id' => $originProduct->id,
            'product_variant_id' => $originVariant->id,
            'type' => 'transfer_request_in',
            'quantity' => '4.0000',
        ]);
    }

    public function test_origin_request_with_variant_rejects_foreign_variant(): void
    {
        $originTenant = Tenant::create(['name' => 'Origen Var 2', 'slug' => 'origen-var-2']);
        $destinationTenant = Tenant::create(['name' => 'Destino Var 2', 'slug' => 'destino-var-2']);
        [$originWarehouse, $originProduct] = $this->warehouseProductVariant($originTenant, 'TREQ-VAR-O2');
        [, $otherProduct, $otherVariant] = $this->warehouseProductVariant($originTenant, 'TREQ-VAR-O3');
        $originUser = $this->userInTenant($originTenant);
        $this->grantRole($originTenant, $originUser, 'Gerente Var Origen 2', ['inventory_transfer_requests.create', 'inventory_transfer_requests.view']);

        $this
            ->actingAs($originUser)
            ->withHeader('X-Tenant', $originTenant->slug)
            ->postJson('/api/inventory-transfer-requests', [
                'destination_tenant_slug' => $destinationTenant->slug,
                'from_warehouse_id' => $originWarehouse->id,
                'reason' => 'Variante ajena',
                'items' => [[
                    'product_id' => $originProduct->id,
                    'product_variant_id' => $otherVariant->id,
                    'quantity' => 2,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_variant_id']);
    }

    private function warehouseProductVariant(Tenant $tenant, string $sku): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal {$sku}", 'code' => "BR-{$sku}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Almacen {$sku}", 'code' => "WH-{$sku}"]);
        $product = Product::create([
            'name' => "Producto {$sku}",
            'sku' => $sku,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'color' => 'Azul', 'position' => 0]);

        return [$warehouse, $product, $variant];
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
            reason: 'Stock variante interempresa',
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

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
