<?php

namespace Tests\Feature\InventoryTransferRequests;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest;
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

class InventoryTransferRequestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_origin_can_request_and_destination_can_accept_quantity_transfer(): void
    {
        $originTenant = Tenant::create(['name' => 'Empresa Origen', 'slug' => 'empresa-origen']);
        $destinationTenant = Tenant::create(['name' => 'Empresa Destino', 'slug' => 'empresa-destino']);
        [$originWarehouse, $originProduct] = $this->warehouseAndProduct($originTenant, 'TREQ-QTY-O', Product::TRACKING_QUANTITY);
        [$destinationWarehouse, $destinationProduct] = $this->warehouseAndProduct($destinationTenant, 'TREQ-QTY-D', Product::TRACKING_QUANTITY);
        $originUser = $this->userInTenant($originTenant);
        $destinationUser = $this->userInTenant($destinationTenant);
        $this->grantRole($originTenant, $originUser, 'Gerente Origen', ['inventory_transfer_requests.create', 'inventory_transfer_requests.view']);
        $this->grantRole($destinationTenant, $destinationUser, 'Gerente Destino', ['inventory_transfer_requests.respond', 'inventory_transfer_requests.view']);
        $this->stock($originTenant, $originWarehouse, $originProduct, $originUser, 10);

        $createResponse = $this
            ->actingAs($originUser)
            ->withHeader('X-Tenant', $originTenant->slug)
            ->postJson('/api/inventory-transfer-requests', [
                'destination_tenant_slug' => $destinationTenant->slug,
                'from_warehouse_id' => $originWarehouse->id,
                'reason' => 'Envio entre empresas',
                'items' => [[
                    'product_id' => $originProduct->id,
                    'quantity' => 4,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', InventoryTransferRequest::STATUS_REQUESTED);

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
            ->assertJsonPath('data.status', InventoryTransferRequest::STATUS_COMPLETED)
            ->assertJsonPath('data.destination_warehouse_id', $destinationWarehouse->id);

        $this->useTenant($originTenant);
        $this->assertSame(6.0, (float) $this->balance($originWarehouse, $originProduct)->quantity_available);
        $this->useTenant($destinationTenant);
        $this->assertSame(4.0, (float) $this->balance($destinationWarehouse, $destinationProduct)->quantity_available);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $originTenant->id,
            'type' => 'transfer_request_out',
            'reference_type' => InventoryTransferRequest::class,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $destinationTenant->id,
            'type' => 'transfer_request_in',
            'reference_type' => InventoryTransferRequest::class,
        ]);
    }

    public function test_intercompany_serialized_transfer_moves_imei_between_companies_after_acceptance(): void
    {
        $originTenant = Tenant::create(['name' => 'Empresa Origen', 'slug' => 'empresa-origen']);
        $destinationTenant = Tenant::create(['name' => 'Empresa Destino', 'slug' => 'empresa-destino']);
        [$originWarehouse, $originProduct] = $this->warehouseAndProduct($originTenant, 'TREQ-IMEI-O', Product::TRACKING_SERIALIZED);
        [$destinationWarehouse, $destinationProduct] = $this->warehouseAndProduct($destinationTenant, 'TREQ-IMEI-D', Product::TRACKING_SERIALIZED);
        $originUser = $this->userInTenant($originTenant);
        $destinationUser = $this->userInTenant($destinationTenant);
        $this->grantRole($originTenant, $originUser, 'Gerente Origen', ['inventory_transfer_requests.create', 'inventory_transfer_requests.view']);
        $this->grantRole($destinationTenant, $destinationUser, 'Gerente Destino', ['inventory_transfer_requests.respond', 'inventory_transfer_requests.view']);
        $movement = $this->stock($originTenant, $originWarehouse, $originProduct, $originUser, 2);
        $units = $this->units($originTenant, $originWarehouse, $originProduct, $movement->id, '862000', 2);

        $createResponse = $this
            ->actingAs($originUser)
            ->withHeader('X-Tenant', $originTenant->slug)
            ->postJson('/api/inventory-transfer-requests', [
                'destination_user_email' => $destinationUser->email,
                'from_warehouse_id' => $originWarehouse->id,
                'items' => [[
                    'product_id' => $originProduct->id,
                    'quantity' => 1,
                    'product_unit_ids' => [$units[0]->id],
                ]],
            ])
            ->assertCreated();

        $this
            ->actingAs($destinationUser)
            ->withHeader('X-Tenant', $destinationTenant->slug)
            ->postJson("/api/inventory-transfer-requests/{$createResponse->json('data.id')}/accept", [
                'destination_warehouse_id' => $destinationWarehouse->id,
                'items' => [[
                    'request_item_id' => $createResponse->json('data.items.0.id'),
                    'destination_product_id' => $destinationProduct->id,
                    // El destino envia el IMEI del payload; el backend hace
                    // lookup por serial_number para eliminar la unit del origin.
                    'serial_units' => [[
                        'serial_type' => 'imei',
                        'serial_number' => $units[0]->serial_number,
                    ]],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransferRequest::STATUS_COMPLETED);

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $originTenant->id,
            'id' => $units[0]->id,
            'status' => ProductUnit::STATUS_REMOVED,
            'warehouse_id' => null,
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $destinationTenant->id,
            'product_id' => $destinationProduct->id,
            'warehouse_id' => $destinationWarehouse->id,
            'serial_number' => $units[0]->serial_number,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
    }

    public function test_destination_can_choose_imeis_when_origin_did_not_provide_them(): void
    {
        // Caso: Empresa A pide 2 celulares SIN especificar IMEIs (solo
        // producto + cantidad). Empresa B acepta y elige 2 IMEIs de SU stock.
        // El backend debe buscar las ProductUnit del origin por serial_number
        // y eliminarlas, luego crear nuevas ProductUnit en el destination.
        $originTenant = Tenant::create(['name' => 'Empresa Origen', 'slug' => 'empresa-origen-2']);
        $destinationTenant = Tenant::create(['name' => 'Empresa Destino', 'slug' => 'empresa-destino-2']);
        [$originWarehouse, $originProduct] = $this->warehouseAndProduct($originTenant, 'TREQ-NOIMEI-O', Product::TRACKING_SERIALIZED);
        [$destinationWarehouse, $destinationProduct] = $this->warehouseAndProduct($destinationTenant, 'TREQ-NOIMEI-D', Product::TRACKING_SERIALIZED);
        $originUser = $this->userInTenant($originTenant);
        $destinationUser = $this->userInTenant($destinationTenant);
        $this->grantRole($originTenant, $originUser, 'Gerente Origen 2', ['inventory_transfer_requests.create', 'inventory_transfer_requests.view']);
        $this->grantRole($destinationTenant, $destinationUser, 'Gerente Destino 2', ['inventory_transfer_requests.respond', 'inventory_transfer_requests.view']);
        $movement = $this->stock($originTenant, $originWarehouse, $originProduct, $originUser, 2);
        $originUnits = $this->units($originTenant, $originWarehouse, $originProduct, $movement->id, 'IMEI-NEW-', 2);

        // Crear: sin product_unit_ids ni serial_units.
        $createResponse = $this
            ->actingAs($originUser)
            ->withHeader('X-Tenant', $originTenant->slug)
            ->postJson('/api/inventory-transfer-requests', [
                'destination_user_email' => $destinationUser->email,
                'from_warehouse_id' => $originWarehouse->id,
                'items' => [[
                    'product_id' => $originProduct->id,
                    'quantity' => 2,
                ]],
            ])
            ->assertCreated();

        // Aceptar: el destino elige los 2 IMEIs via serial_numbers.
        $this
            ->actingAs($destinationUser)
            ->withHeader('X-Tenant', $destinationTenant->slug)
            ->postJson("/api/inventory-transfer-requests/{$createResponse->json('data.id')}/accept", [
                'destination_warehouse_id' => $destinationWarehouse->id,
                'items' => [[
                    'request_item_id' => $createResponse->json('data.items.0.id'),
                    'destination_product_id' => $destinationProduct->id,
                    'serial_units' => [
                        ['serial_type' => 'imei', 'serial_number' => $originUnits[0]->serial_number],
                        ['serial_type' => 'imei', 'serial_number' => $originUnits[1]->serial_number],
                    ],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransferRequest::STATUS_COMPLETED);

        // Verificar que las ProductUnit del origin se eliminaron (status=removed, warehouse=null).
        $this->assertDatabaseHas('product_units', [
            'id' => $originUnits[0]->id,
            'status' => ProductUnit::STATUS_REMOVED,
            'warehouse_id' => null,
        ]);
        $this->assertDatabaseHas('product_units', [
            'id' => $originUnits[1]->id,
            'status' => ProductUnit::STATUS_REMOVED,
            'warehouse_id' => null,
        ]);

        // Verificar que se crearon 2 nuevas ProductUnit en el destination.
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $destinationTenant->id,
            'product_id' => $destinationProduct->id,
            'warehouse_id' => $destinationWarehouse->id,
            'serial_number' => $originUnits[0]->serial_number,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $destinationTenant->id,
            'product_id' => $destinationProduct->id,
            'warehouse_id' => $destinationWarehouse->id,
            'serial_number' => $originUnits[1]->serial_number,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
    }

    public function test_destination_can_reject_without_moving_inventory(): void
    {
        $originTenant = Tenant::create(['name' => 'Empresa Origen', 'slug' => 'empresa-origen']);
        $destinationTenant = Tenant::create(['name' => 'Empresa Destino', 'slug' => 'empresa-destino']);
        [$originWarehouse, $originProduct] = $this->warehouseAndProduct($originTenant, 'TREQ-REJ-O', Product::TRACKING_QUANTITY);
        $originUser = $this->userInTenant($originTenant);
        $destinationUser = $this->userInTenant($destinationTenant);
        $this->grantRole($originTenant, $originUser, 'Gerente Origen', ['inventory_transfer_requests.create', 'inventory_transfer_requests.view']);
        $this->grantRole($destinationTenant, $destinationUser, 'Gerente Destino', ['inventory_transfer_requests.respond', 'inventory_transfer_requests.view']);
        $this->stock($originTenant, $originWarehouse, $originProduct, $originUser, 5);

        $createResponse = $this
            ->actingAs($originUser)
            ->withHeader('X-Tenant', $originTenant->slug)
            ->postJson('/api/inventory-transfer-requests', [
                'destination_tenant_slug' => $destinationTenant->slug,
                'from_warehouse_id' => $originWarehouse->id,
                'items' => [[
                    'product_id' => $originProduct->id,
                    'quantity' => 2,
                ]],
            ])
            ->assertCreated();

        $this
            ->actingAs($destinationUser)
            ->withHeader('X-Tenant', $destinationTenant->slug)
            ->postJson("/api/inventory-transfer-requests/{$createResponse->json('data.id')}/reject", [
                'response_notes' => 'No corresponde.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransferRequest::STATUS_REJECTED);

        $this->useTenant($originTenant);
        $this->assertSame(5.0, (float) $this->balance($originWarehouse, $originProduct)->quantity_available);
        $this->assertDatabaseMissing('stock_movements', [
            'tenant_id' => $originTenant->id,
            'type' => 'adjustment_out',
            'reference_type' => InventoryTransferRequest::class,
        ]);
    }

    public function test_request_visibility_and_response_are_limited_to_origin_and_destination(): void
    {
        $originTenant = Tenant::create(['name' => 'Empresa Origen', 'slug' => 'empresa-origen']);
        $destinationTenant = Tenant::create(['name' => 'Empresa Destino', 'slug' => 'empresa-destino']);
        $thirdTenant = Tenant::create(['name' => 'Empresa Tercera', 'slug' => 'empresa-tercera']);
        [$originWarehouse, $originProduct] = $this->warehouseAndProduct($originTenant, 'TREQ-ISO-O', Product::TRACKING_QUANTITY);
        [, $thirdProduct] = $this->warehouseAndProduct($thirdTenant, 'TREQ-ISO-T', Product::TRACKING_QUANTITY);
        $originUser = $this->userInTenant($originTenant);
        $destinationUser = $this->userInTenant($destinationTenant);
        $thirdUser = $this->userInTenant($thirdTenant);
        $this->grantRole($originTenant, $originUser, 'Gerente Origen', ['inventory_transfer_requests.create', 'inventory_transfer_requests.view']);
        $this->grantRole($destinationTenant, $destinationUser, 'Gerente Destino', ['inventory_transfer_requests.respond', 'inventory_transfer_requests.view']);
        $this->grantRole($thirdTenant, $thirdUser, 'Gerente Tercero', ['inventory_transfer_requests.respond', 'inventory_transfer_requests.view']);
        $this->stock($originTenant, $originWarehouse, $originProduct, $originUser, 5);

        $createResponse = $this
            ->actingAs($originUser)
            ->withHeader('X-Tenant', $originTenant->slug)
            ->postJson('/api/inventory-transfer-requests', [
                'destination_tenant_slug' => $destinationTenant->slug,
                'from_warehouse_id' => $originWarehouse->id,
                'reason' => 'Solo destino',
                'items' => [[
                    'product_id' => $originProduct->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated();

        $this
            ->actingAs($thirdUser)
            ->withHeader('X-Tenant', $thirdTenant->slug)
            ->getJson('/api/inventory-transfer-requests')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this
            ->actingAs($thirdUser)
            ->withHeader('X-Tenant', $thirdTenant->slug)
            ->postJson("/api/inventory-transfer-requests/{$createResponse->json('data.id')}/accept", [
                'destination_warehouse_id' => $this->warehouseAndProduct($thirdTenant, 'TREQ-ISO-T2', Product::TRACKING_QUANTITY)[0]->id,
                'items' => [[
                    'request_item_id' => $createResponse->json('data.items.0.id'),
                    'destination_product_id' => $thirdProduct->id,
                ]],
            ])
            ->assertForbidden();
    }

    public function test_origin_can_cancel_pending_request(): void
    {
        $originTenant = Tenant::create(['name' => 'Empresa Origen', 'slug' => 'empresa-origen']);
        $destinationTenant = Tenant::create(['name' => 'Empresa Destino', 'slug' => 'empresa-destino']);
        [$originWarehouse, $originProduct] = $this->warehouseAndProduct($originTenant, 'TREQ-CAN-O', Product::TRACKING_QUANTITY);
        $originUser = $this->userInTenant($originTenant);
        $this->grantRole($originTenant, $originUser, 'Gerente Origen', [
            'inventory_transfer_requests.create',
            'inventory_transfer_requests.view',
            'inventory_transfer_requests.cancel',
        ]);
        $this->stock($originTenant, $originWarehouse, $originProduct, $originUser, 5);

        $createResponse = $this
            ->actingAs($originUser)
            ->withHeader('X-Tenant', $originTenant->slug)
            ->postJson('/api/inventory-transfer-requests', [
                'destination_tenant_slug' => $destinationTenant->slug,
                'from_warehouse_id' => $originWarehouse->id,
                'items' => [[
                    'product_id' => $originProduct->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated();

        $this
            ->actingAs($originUser)
            ->withHeader('X-Tenant', $originTenant->slug)
            ->postJson("/api/inventory-transfer-requests/{$createResponse->json('data.id')}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransferRequest::STATUS_CANCELLED);
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
