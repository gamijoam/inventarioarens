<?php

namespace Tests\Feature\Workshop;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Workshop\Models\ServiceOrder;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Contrato del modulo Taller (ordenes de servicio).
 *
 * Cubre (TDD Fase 0 + Fase 1):
 *  - Crear orden de reparacion -> status received + numero secuencial SO-000001.
 *  - Orden de garantia exige resolution (workshop | exchange | return_supplier).
 *  - Diagnostico con mano de obra (labor) -> status diagnosed.
 *  - Asignar tecnico + almacen de trabajo.
 *  - Agregar piezas del inventario (snapshot precio/costo, valida stock).
 *  - Completar: descuenta piezas del inventario (stock_movement con unit_cost
 *    y reference_type ServiceOrder), marca entregado.
 *  - Transiciones de estado invalidas -> 422.
 *  - Aislamiento cross-tenant y gate de permisos.
 */
class ServiceOrderApiTest extends TestCase
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

    public function test_user_can_create_repair_service_order_received(): void
    {
        [$tenant, $user, $warehouse] = $this->scaffold('SO-REP');
        $this->grantRole($tenant, $user, 'Taller', ['service_orders.view', 'service_orders.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/service-orders', [
                'type' => ServiceOrder::TYPE_REPAIR,
                'customer_name' => 'Juan Perez',
                'customer_phone' => '04121234567',
                'device_description' => 'iPhone 11 64GB',
                'issue_description' => 'Pantalla rota y no enciende',
                'warehouse_id' => $warehouse->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', ServiceOrder::STATUS_RECEIVED)
            ->assertJsonPath('data.type', ServiceOrder::TYPE_REPAIR)
            ->assertJsonPath('data.order_number', 'SO-000001')
            ->assertJsonPath('data.warehouse_id', $warehouse->id)
            ->assertJsonPath('data.customer_name', 'Juan Perez');
    }

    public function test_warranty_service_order_requires_resolution(): void
    {
        [$tenant, $user, $warehouse] = $this->scaffold('SO-GAR');
        $this->grantRole($tenant, $user, 'Taller', ['service_orders.view', 'service_orders.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/service-orders', [
                'type' => ServiceOrder::TYPE_WARRANTY,
                'customer_name' => 'Ana',
                'device_description' => 'Lavadora 16kg',
                'issue_description' => 'No centrifuga',
                'warehouse_id' => $warehouse->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resolution']);
    }

    public function test_warranty_service_order_accepts_workshop_exchange_return_supplier(): void
    {
        [$tenant, $user, $warehouse] = $this->scaffold('SO-RES');
        $this->grantRole($tenant, $user, 'Taller', ['service_orders.view', 'service_orders.create']);

        foreach ([ServiceOrder::RESOLUTION_WORKSHOP, ServiceOrder::RESOLUTION_EXCHANGE, ServiceOrder::RESOLUTION_RETURN_SUPPLIER] as $resolution) {
            $this
                ->actingAs($user)
                ->withHeader('X-Tenant', $tenant->slug)
                ->postJson('/api/service-orders', [
                    'type' => ServiceOrder::TYPE_WARRANTY,
                    'resolution' => $resolution,
                    'customer_name' => 'Ana',
                    'device_description' => 'Lavadora',
                    'warehouse_id' => $warehouse->id,
                ])
                ->assertCreated()
                ->assertJsonPath('data.resolution', $resolution);
        }
    }

    public function test_user_can_diagnose_order_with_labor_estimate(): void
    {
        [$tenant, $user, $warehouse] = $this->scaffold('SO-DIA');
        $this->grantRole($tenant, $user, 'Taller', ['service_orders.view', 'service_orders.create', 'service_orders.update']);

        $orderId = $this->createOrder($user, $tenant, $warehouse, ServiceOrder::TYPE_REPAIR);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/service-orders/{$orderId}/diagnose", [
                'diagnosis' => 'Se requiere cambio de pantalla y flex.',
                'labor_base_amount' => 35,
                'labor_local_amount' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ServiceOrder::STATUS_DIAGNOSED)
            ->assertJsonPath('data.diagnosis', 'Se requiere cambio de pantalla y flex.')
            ->assertJsonPath('data.labor_base_amount', '35.0000');
    }

    public function test_user_can_assign_technician_and_warehouse(): void
    {
        [$tenant, $user, $warehouse] = $this->scaffold('SO-TEC');
        $technician = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Taller', ['service_orders.view', 'service_orders.create', 'service_orders.assign_technician']);

        $orderId = $this->createOrder($user, $tenant, $warehouse, ServiceOrder::TYPE_REPAIR);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/service-orders/{$orderId}/assign-technician", [
                'technician_id' => $technician->id,
                'warehouse_id' => $warehouse->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.technician_id', $technician->id)
            ->assertJsonPath('data.warehouse_id', $warehouse->id);
    }

    public function test_user_can_add_part_with_snapshot_price_and_cost(): void
    {
        [$tenant, $user, $warehouse] = $this->scaffold('SO-PART');
        $this->grantRole($tenant, $user, 'Taller', ['service_orders.view', 'service_orders.create', 'service_orders.update']);
        $product = $this->product($tenant, 'PIEZA-A');
        $product->update(['last_purchase_cost' => 50, 'average_cost' => 50]);
        $this->stock($tenant, $warehouse, $product, $user, 10);

        $orderId = $this->createOrder($user, $tenant, $warehouse, ServiceOrder::TYPE_REPAIR);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/service-orders/{$orderId}/parts", [
                'product_id' => $product->id,
                'quantity' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.quantity', '2.0000')
            ->assertJsonPath('data.status', ServiceOrder::PART_STATUS_PENDING)
            ->assertJsonPath('data.unit_price', '100.0000')
            ->assertJsonPath('data.unit_cost', '50.0000');
    }

    public function test_add_part_rejects_when_insufficient_stock(): void
    {
        [$tenant, $user, $warehouse] = $this->scaffold('SO-NOSTOCK');
        $this->grantRole($tenant, $user, 'Taller', ['service_orders.view', 'service_orders.create', 'service_orders.update']);
        $product = $this->product($tenant, 'PIEZA-SIN');

        $orderId = $this->createOrder($user, $tenant, $warehouse, ServiceOrder::TYPE_REPAIR);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/service-orders/{$orderId}/parts", [
                'product_id' => $product->id,
                'quantity' => 1,
            ])
            ->assertUnprocessable();
    }

    public function test_complete_order_deducts_parts_from_inventory(): void
    {
        [$tenant, $user, $warehouse] = $this->scaffold('SO-COMP');
        $this->grantRole($tenant, $user, 'Taller', ['service_orders.view', 'service_orders.create', 'service_orders.update', 'service_orders.close']);
        $product = $this->product($tenant, 'PIEZA-COMP');
        $product->update(['last_purchase_cost' => 50, 'average_cost' => 50]);
        $this->stock($tenant, $warehouse, $product, $user, 10);

        $orderId = $this->createOrder($user, $tenant, $warehouse, ServiceOrder::TYPE_REPAIR);

        // Diagnosticar (obligatorio antes de completar).
        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/service-orders/{$orderId}/diagnose", [
                'diagnosis' => 'Cambio de pieza',
                'labor_base_amount' => 20,
            ])
            ->assertOk();

        // Agregar pieza y completar.
        $partId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/service-orders/{$orderId}/parts", [
                'product_id' => $product->id,
                'quantity' => 3,
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/service-orders/{$orderId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', ServiceOrder::STATUS_DELIVERED);

        // Stock descontado (10 - 3 = 7).
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '7.0000',
        ]);

        // Movimiento de stock con costo y referencia a la orden.
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'adjustment_out',
            'quantity' => '3.0000',
            'unit_cost' => '50.0000',
            'reference_type' => ServiceOrder::class,
        ]);

        // La pieza quedo consumida.
        $this->assertDatabaseHas('service_order_parts', [
            'id' => $partId,
            'status' => ServiceOrder::PART_STATUS_CONSUMED,
        ]);
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        [$tenant, $user, $warehouse] = $this->scaffold('SO-TRANS');
        $this->grantRole($tenant, $user, 'Taller', ['service_orders.view', 'service_orders.create', 'service_orders.close']);

        $orderId = $this->createOrder($user, $tenant, $warehouse, ServiceOrder::TYPE_REPAIR);

        // Recibido -> entregado directamente es invalido (debe pasar por el flujo).
        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/service-orders/{$orderId}/complete")
            ->assertUnprocessable();
    }

    public function test_cross_tenant_cannot_access_orders(): void
    {
        [$tenantA, $userA, $warehouseA] = $this->scaffold('SO-TA');
        $this->grantRole($tenantA, $userA, 'Taller A', ['service_orders.view', 'service_orders.create', 'service_orders.update']);
        $orderId = $this->createOrder($userA, $tenantA, $warehouseA, ServiceOrder::TYPE_REPAIR);

        [$tenantB, $userB, $warehouseB] = $this->scaffold('SO-TB');
        $this->grantRole($tenantB, $userB, 'Taller B', ['service_orders.view', 'service_orders.create']);

        $response = $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson("/api/service-orders/{$orderId}");
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_user_without_permission_cannot_create_order(): void
    {
        [$tenant, $user, $warehouse] = $this->scaffold('SO-PERM');
        $this->grantRole($tenant, $user, 'Sin permiso', []);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/service-orders', [
                'type' => ServiceOrder::TYPE_REPAIR,
                'warehouse_id' => $warehouse->id,
            ]);

        $this->assertContains($response->getStatusCode(), [403, 422]);
    }

    // ---- Helpers ----

    private function scaffold(string $sku): array
    {
        $tenant = Tenant::create(['name' => "Empresa {$sku}", 'slug' => 'empresa-'.strtolower($sku)]);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal {$sku}", 'code' => "BR-{$sku}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Taller {$sku}", 'code' => "WH-{$sku}"]);
        $user = $this->userInTenant($tenant);

        return [$tenant, $user, $warehouse];
    }

    private function createOrder(User $user, Tenant $tenant, Warehouse $warehouse, string $type, ?string $resolution = null): int
    {
        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/service-orders', [
                'type' => $type,
                'resolution' => $resolution,
                'customer_name' => 'Cliente',
                'device_description' => 'Equipo',
                'issue_description' => 'Falla',
                'warehouse_id' => $warehouse->id,
            ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    private function product(Tenant $tenant, string $sku): Product
    {
        $this->useTenant($tenant);

        return Product::create([
            'name' => "Pieza {$sku}",
            'sku' => $sku,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
    }

    private function stock(Tenant $tenant, Warehouse $warehouse, Product $product, User $user, float $quantity): void
    {
        $this->useTenant($tenant);

        if ($quantity <= 0) {
            return;
        }

        app(InventoryMovementService::class)->purchase(
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            unitCost: 50,
            createdBy: $user,
            reason: "Stock prueba {$product->sku}",
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

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
