<?php

namespace Tests\Feature\Purchases;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Products\Models\Product;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PurchaseOrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_draft_purchase_without_moving_inventory(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_QUANTITY, 'AUD-001');
        $supplier = $this->supplier($tenant, 'Proveedor Demo', '100');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Compras', ['purchases.create', 'purchases.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/purchases', [
                'supplier_id' => $supplier->id,
                'document_number' => 'FAC-001',
                'purchase_currency' => PurchaseOrder::CURRENCY_USD,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'unit_cost' => 20,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PurchaseOrder::STATUS_DRAFT)
            ->assertJsonPath('data.supplier.name', 'Proveedor Demo')
            ->assertJsonPath('data.total_base_amount', '100.0000')
            ->assertJsonPath('data.received_base_amount', '0.0000')
            ->assertJsonPath('data.items.0.stock_movement_id', null);

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_receive_purchase_partially_then_fully_updates_inventory_and_payable(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_QUANTITY, 'AUD-002');
        $supplier = $this->supplier($tenant, 'Proveedor Demo', '101');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Compras', ['purchases.create', 'purchases.approve', 'purchases.view']);

        $purchaseId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/purchases', [
                'supplier_id' => $supplier->id,
                'document_number' => 'FAC-PARCIAL-001',
                'issued_at' => '2026-07-02',
                'due_date' => '2026-07-16',
                'purchase_currency' => PurchaseOrder::CURRENCY_USD,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 3,
                    'unit_cost' => 15,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $purchaseItemId = PurchaseOrder::find($purchaseId)->items()->first()->id;

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/purchases/{$purchaseId}/receive", [
                'items' => [[
                    'purchase_item_id' => $purchaseItemId,
                    'quantity' => 1,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PurchaseOrder::STATUS_PARTIALLY_RECEIVED)
            ->assertJsonPath('data.issued_at', '2026-07-02')
            ->assertJsonPath('data.due_date', '2026-07-16')
            ->assertJsonPath('data.received_base_amount', '15.0000');

        $this->assertNotNull($response->json('data.items.0.stock_movement_id'));

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '1.0000',
        ]);
        $this->assertDatabaseHas('accounts_payables', [
            'tenant_id' => $tenant->id,
            'document_number' => 'FAC-PARCIAL-001',
            'original_base_amount' => '15.0000',
            'balance_base_amount' => '15.0000',
            'due_date' => '2026-07-16',
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/purchases/{$purchaseId}/receive")
            ->assertOk()
            ->assertJsonPath('data.status', PurchaseOrder::STATUS_RECEIVED)
            ->assertJsonPath('data.received_base_amount', '45.0000');

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '3.0000',
        ]);
        $this->assertDatabaseHas('accounts_payables', [
            'tenant_id' => $tenant->id,
            'document_number' => 'FAC-PARCIAL-001',
            'original_base_amount' => '45.0000',
            'balance_base_amount' => '45.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'reference_type' => PurchaseOrder::class,
            'reference_id' => $purchaseId,
        ]);
    }

    public function test_receive_purchase_rejects_more_than_pending_quantity(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_QUANTITY, 'AUD-OVER');
        $supplier = $this->supplier($tenant, 'Proveedor Demo', '104');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Compras', ['purchases.create', 'purchases.approve']);

        $purchaseId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/purchases', [
                'supplier_id' => $supplier->id,
                'purchase_currency' => PurchaseOrder::CURRENCY_USD,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_cost' => 15,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $purchaseItemId = PurchaseOrder::find($purchaseId)->items()->first()->id;

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/purchases/{$purchaseId}/receive", [
                'items' => [[
                    'purchase_item_id' => $purchaseItemId,
                    'quantity' => 3,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_receive_serialized_purchase_creates_product_units(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_SERIALIZED, 'SAM-001');
        $supplier = $this->supplier($tenant, 'Proveedor Telefonos', '102');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Compras', ['purchases.create', 'purchases.approve']);

        $purchaseId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/purchases', [
                'supplier_id' => $supplier->id,
                'purchase_currency' => PurchaseOrder::CURRENCY_USD,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_cost' => 80,
                    'serial_units' => [
                        ['serial_type' => ProductUnit::SERIAL_TYPE_IMEI, 'serial_number' => '860001000000001'],
                        ['serial_type' => ProductUnit::SERIAL_TYPE_IMEI, 'serial_number' => '860001000000002'],
                    ],
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/purchases/{$purchaseId}/receive")
            ->assertOk()
            ->assertJsonPath('data.items.0.serial_units.0.serial_number', '860001000000001');

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860001000000001',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $this->assertSame(2, ProductUnit::withoutGlobalScopes()->where('product_id', $product->id)->count());
    }

    public function test_purchase_in_ves_stores_rate_snapshot(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product, $rateType] = $this->product($tenant, Product::TRACKING_QUANTITY, 'AUD-003', true);
        $supplier = $this->supplier($tenant, 'Proveedor Demo', '103');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Compras', ['purchases.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/purchases', [
                'supplier_id' => $supplier->id,
                'purchase_currency' => PurchaseOrder::CURRENCY_VES,
                'exchange_rate_type_id' => $rateType->id,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_cost' => 60000,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.exchange_rate_type_code', 'BCV')
            ->assertJsonPath('data.exchange_rate', '600.000000')
            ->assertJsonPath('data.total_base_amount', '200.0000')
            ->assertJsonPath('data.total_local_amount', '120000.0000');
    }

    public function test_purchases_do_not_mix_companies_and_reject_foreign_resources(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        [$warehouseA, $productA] = $this->product($tenantA, Product::TRACKING_QUANTITY, 'A-001');
        [, $productB] = $this->product($tenantB, Product::TRACKING_QUANTITY, 'B-001');
        $supplierB = $this->supplier($tenantB, 'Proveedor B', '200');
        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Compras', ['purchases.create', 'purchases.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/purchases', [
                'supplier_id' => $supplierB->id,
                'purchase_currency' => PurchaseOrder::CURRENCY_USD,
                'items' => [[
                    'warehouse_id' => $warehouseA->id,
                    'product_id' => $productA->id,
                    'quantity' => 1,
                    'unit_cost' => 10,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_id']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/purchases', [
                'purchase_currency' => PurchaseOrder::CURRENCY_USD,
                'items' => [[
                    'warehouse_id' => $warehouseA->id,
                    'product_id' => $productB->id,
                    'quantity' => 1,
                    'unit_cost' => 10,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_id']);
    }

    public function test_purchase_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_QUANTITY, 'AUD-004');
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/purchases', [
                'purchase_currency' => PurchaseOrder::CURRENCY_USD,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_cost' => 10,
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

    private function product(Tenant $tenant, string $trackingType, string $sku, bool $withRate = false): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal {$sku}", 'code' => "BR-{$sku}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Almacen {$sku}", 'code' => "WH-{$sku}"]);
        $rateType = null;

        if ($withRate) {
            $rateType = ExchangeRateType::create(['code' => 'BCV', 'name' => 'Tasa BCV', 'is_default' => true]);
            ExchangeRate::create([
                'exchange_rate_type_id' => $rateType->id,
                'rate' => 600,
                'effective_at' => '2026-07-02 12:00:00',
                'is_active' => true,
            ]);
        }

        $product = Product::create([
            'name' => "Producto {$sku}",
            'sku' => $sku,
            'tracking_type' => $trackingType,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        return [$warehouse, $product, $rateType];
    }

    private function supplier(Tenant $tenant, string $name, string $documentNumber): Supplier
    {
        $this->useTenant($tenant);

        return Supplier::create([
            'name' => $name,
            'document_type' => Supplier::DOCUMENT_J,
            'document_number' => $documentNumber,
        ]);
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
