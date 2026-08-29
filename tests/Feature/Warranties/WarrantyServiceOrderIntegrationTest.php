<?php

namespace Tests\Feature\Warranties;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Warranties\Models\WarrantyClaim;
use App\Modules\Warranties\Models\WarrantyPolicy;
use App\Modules\Workshop\Models\ServiceOrder;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Contrato de la integracion Garantia -> Taller (Fase 3):
 *  - Enviar una reclamacion de garantia al taller crea una orden de servicio
 *    vinculada y la marca como under_review.
 *  - No se puede enviar dos veces la misma garantia al taller.
 *  - Al completar la orden se resuelve la garantia segun su tratamiento:
 *    workshop -> repair, exchange -> replacement, return_supplier -> return_supplier.
 *  - Al cancelar la orden, la garantia vuelve a received.
 */
class WarrantyServiceOrderIntegrationTest extends TestCase
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

    public function test_warranty_claim_sent_to_workshop_creates_linked_order_and_marks_under_review(): void
    {
        [$tenant, $user, $warehouse, $product, $claim] = $this->claim('GAR-WS');
        $this->grantRole($tenant, $user, 'Taller', ['service_orders.view', 'service_orders.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/service-orders', [
                'type' => ServiceOrder::TYPE_WARRANTY,
                'warranty_claim_id' => $claim->id,
                'resolution' => ServiceOrder::RESOLUTION_WORKSHOP,
                'customer_name' => 'Cliente',
                'device_description' => 'Equipo en garantia',
                'warehouse_id' => $warehouse->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', ServiceOrder::TYPE_WARRANTY)
            ->assertJsonPath('data.warranty_claim_id', $claim->id);

        $this->assertDatabaseHas('warranty_claims', [
            'id' => $claim->id,
            'status' => WarrantyClaim::STATUS_UNDER_REVIEW,
        ]);
    }

    public function test_claim_cannot_be_sent_to_workshop_twice(): void
    {
        [$tenant, $user, $warehouse, $product, $claim] = $this->claim('GAR-DUP');
        $this->grantRole($tenant, $user, 'Taller', ['service_orders.view', 'service_orders.create']);

        $payload = [
            'type' => ServiceOrder::TYPE_WARRANTY,
            'warranty_claim_id' => $claim->id,
            'resolution' => ServiceOrder::RESOLUTION_WORKSHOP,
            'warehouse_id' => $warehouse->id,
        ];

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/service-orders', $payload)->assertCreated();

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/service-orders', $payload)
            ->assertUnprocessable();
    }

    public function test_completing_workshop_order_resolves_claim_as_repair_and_restores_unit(): void
    {
        [$tenant, $user, $warehouse, $product, $claim, $unit] = $this->claim('GAR-REP', true);
        $this->grantRole($tenant, $user, 'Taller', [
            'service_orders.view', 'service_orders.create', 'service_orders.update', 'service_orders.close',
        ]);

        $orderId = $this->workshopOrder($user, $tenant, $warehouse, $claim, ServiceOrder::RESOLUTION_WORKSHOP);

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/service-orders/{$orderId}/diagnose", [
                'diagnosis' => 'Cambio de pantalla',
                'labor_base_amount' => 30,
            ])->assertOk();

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/service-orders/{$orderId}/complete")->assertOk();

        $this->assertDatabaseHas('warranty_claims', [
            'id' => $claim->id,
            'status' => WarrantyClaim::STATUS_CLOSED,
            'resolution_type' => WarrantyClaim::RESOLUTION_REPAIR,
        ]);

        if ($unit) {
            $this->assertDatabaseHas('product_units', [
                'id' => $unit->id,
                'status' => ProductUnit::STATUS_SOLD,
            ]);
        }
    }

    public function test_completing_exchange_order_resolves_claim_as_replacement(): void
    {
        [$tenant, $user, $warehouse, $product, $claim] = $this->claim('GAR-EX');
        $this->grantRole($tenant, $user, 'Taller', [
            'service_orders.view', 'service_orders.create', 'service_orders.update', 'service_orders.close',
        ]);

        $orderId = $this->workshopOrder($user, $tenant, $warehouse, $claim, ServiceOrder::RESOLUTION_EXCHANGE);

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/service-orders/{$orderId}/diagnose", ['diagnosis' => 'No reparable'])
            ->assertOk();
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/service-orders/{$orderId}/complete")->assertOk();

        $this->assertDatabaseHas('warranty_claims', [
            'id' => $claim->id,
            'status' => WarrantyClaim::STATUS_CLOSED,
            'resolution_type' => WarrantyClaim::RESOLUTION_REPLACEMENT,
        ]);
    }

    public function test_completing_return_supplier_order_resolves_claim_as_return_supplier(): void
    {
        [$tenant, $user, $warehouse, $product, $claim] = $this->claim('GAR-RET');
        $this->grantRole($tenant, $user, 'Taller', [
            'service_orders.view', 'service_orders.create', 'service_orders.update', 'service_orders.close',
        ]);

        $orderId = $this->workshopOrder($user, $tenant, $warehouse, $claim, ServiceOrder::RESOLUTION_RETURN_SUPPLIER);

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/service-orders/{$orderId}/diagnose", ['diagnosis' => 'No reparable'])
            ->assertOk();
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/service-orders/{$orderId}/complete")->assertOk();

        $this->assertDatabaseHas('warranty_claims', [
            'id' => $claim->id,
            'status' => WarrantyClaim::STATUS_CLOSED,
            'resolution_type' => WarrantyClaim::RESOLUTION_RETURN_SUPPLIER,
        ]);
    }

    public function test_cancelling_order_returns_claim_to_received(): void
    {
        [$tenant, $user, $warehouse, $product, $claim] = $this->claim('GAR-CAN');
        $this->grantRole($tenant, $user, 'Taller', [
            'service_orders.view', 'service_orders.create', 'service_orders.close',
        ]);

        $orderId = $this->workshopOrder($user, $tenant, $warehouse, $claim, ServiceOrder::RESOLUTION_WORKSHOP);

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/service-orders/{$orderId}/cancel")->assertOk();

        $this->assertDatabaseHas('warranty_claims', [
            'id' => $claim->id,
            'status' => WarrantyClaim::STATUS_RECEIVED,
        ]);
    }

    // ---- Helpers ----

    private function claim(string $sku, bool $serialized = false): array
    {
        $tenant = Tenant::create(['name' => "Empresa {$sku}", 'slug' => 'empresa-'.strtolower($sku)]);
        $this->useTenant($tenant);

        $policy = WarrantyPolicy::create([
            'name' => "Garantia {$sku}",
            'duration_days' => 365,
            'coverage_type' => WarrantyPolicy::COVERAGE_STORE,
        ]);

        $branch = Branch::create(['name' => 'Sucursal', 'code' => 'BR-'.$sku]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'WH-'.$sku]);
        $tracking = $serialized ? Product::TRACKING_SERIALIZED : Product::TRACKING_QUANTITY;
        $product = Product::create([
            'name' => "Producto {$sku}",
            'sku' => $sku,
            'tracking_type' => $tracking,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
            'warranty_policy_id' => $policy->id,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['warranties.view', 'warranties.create', 'sales.view', 'sales.create']);

        $movement = app(InventoryMovementService::class)->purchase($warehouse, $product, 1, 80, $user, "Stock {$sku}");
        $unit = null;
        if ($serialized) {
            $unit = ProductUnit::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
                'serial_number' => '860000'.str_pad((string) rand(100000, 999999), 6, '0', STR_PAD_LEFT),
                'status' => ProductUnit::STATUS_AVAILABLE,
                'acquired_stock_movement_id' => $movement->id,
            ]);
        }

        $sale = app(SaleService::class)->createDraft($user, [[
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'product_unit_ids' => $unit ? [$unit->id] : [],
        ]]);
        app(SaleService::class)->confirm($sale, $user);
        $saleItem = $sale->items()->firstOrFail();

        $claim = $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/warranty-claims', [
                'sale_item_id' => $saleItem->id,
                'quantity' => 1,
                'issue_description' => 'Falla cubierta por garantia.',
                'product_unit_id' => $unit?->id,
            ])->assertCreated()->json('data');

        $claim = WarrantyClaim::query()->findOrFail($claim['id']);

        return [$tenant, $user, $warehouse, $product, $claim, $unit];
    }

    private function workshopOrder(User $user, Tenant $tenant, Warehouse $warehouse, WarrantyClaim $claim, string $resolution): int
    {
        $response = $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/service-orders', [
                'type' => ServiceOrder::TYPE_WARRANTY,
                'warranty_claim_id' => $claim->id,
                'resolution' => $resolution,
                'warehouse_id' => $warehouse->id,
            ])->assertCreated();

        return (int) $response->json('data.id');
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
