<?php

namespace Tests\Feature\PurchaseReturns;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Products\Models\Product;
use App\Modules\PurchaseReturns\Models\PurchaseReturn;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Services\PurchaseOrderService;
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

class PurchaseReturnApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_return_received_purchase_and_inventory_decreases(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_QUANTITY, 'PR-001');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Compras', ['purchases.create', 'purchases.approve', 'purchase_returns.create', 'purchase_returns.view']);
        $purchase = $this->receivedPurchase($tenant, $user, $warehouse, $product, 5);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/purchase-returns', [
                'purchase_order_id' => $purchase->id,
                'reason' => 'Mercancia defectuosa',
                'items' => [[
                    'purchase_item_id' => $purchase->items->first()->id,
                    'quantity' => 2,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PurchaseReturn::STATUS_PROCESSED)
            ->assertJsonPath('data.items.0.quantity', '2.0000');

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '3.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'purchase_return',
            'reference_type' => PurchaseReturn::class,
        ]);
    }

    public function test_purchase_return_cannot_exceed_purchased_quantity(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_QUANTITY, 'PR-002');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Compras', ['purchases.create', 'purchases.approve', 'purchase_returns.create']);
        $purchase = $this->receivedPurchase($tenant, $user, $warehouse, $product, 1);

        $payload = [
            'purchase_order_id' => $purchase->id,
            'items' => [[
                'purchase_item_id' => $purchase->items->first()->id,
                'quantity' => 1,
            ]],
        ];

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)->postJson('/api/purchase-returns', $payload)->assertCreated();
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)->postJson('/api/purchase-returns', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_serialized_purchase_return_marks_product_unit_removed(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_SERIALIZED, 'PR-003');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Compras', ['purchases.create', 'purchases.approve', 'purchase_returns.create']);
        $purchase = $this->receivedPurchase($tenant, $user, $warehouse, $product, 1, [[
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860003000000001',
        ]]);
        $unit = ProductUnit::query()->where('serial_number', '860003000000001')->firstOrFail();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/purchase-returns', [
                'purchase_order_id' => $purchase->id,
                'items' => [[
                    'purchase_item_id' => $purchase->items->first()->id,
                    'quantity' => 1,
                    'product_unit_ids' => [$unit->id],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.product_unit_ids.0', $unit->id);

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $unit->id,
            'status' => ProductUnit::STATUS_REMOVED,
        ]);
    }

    public function test_purchase_returns_do_not_mix_companies_and_reject_foreign_purchase(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        [$warehouseA, $productA] = $this->product($tenantA, Product::TRACKING_QUANTITY, 'PR-A');
        [$warehouseB, $productB] = $this->product($tenantB, Product::TRACKING_QUANTITY, 'PR-B');
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantA, $userA, 'Compras A', ['purchases.create', 'purchases.approve', 'purchase_returns.create']);
        $this->grantRole($tenantB, $userB, 'Compras B', ['purchases.create', 'purchases.approve']);
        $purchaseB = $this->receivedPurchase($tenantB, $userB, $warehouseB, $productB, 1);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/purchase-returns', [
                'purchase_order_id' => $purchaseB->id,
                'items' => [[
                    'purchase_item_id' => $purchaseB->items->first()->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['purchase_order_id']);
    }

    public function test_purchase_return_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_QUANTITY, 'PR-004');
        $creator = $this->userInTenant($tenant);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $creator, 'Compras', ['purchases.create', 'purchases.approve']);
        $purchase = $this->receivedPurchase($tenant, $creator, $warehouse, $product, 1);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/purchase-returns', [
                'purchase_order_id' => $purchase->id,
                'items' => [[
                    'purchase_item_id' => $purchase->items->first()->id,
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

    private function receivedPurchase(Tenant $tenant, User $user, Warehouse $warehouse, Product $product, float $quantity, array $serialUnits = []): PurchaseOrder
    {
        $this->useTenant($tenant);
        $supplier = Supplier::create([
            'name' => "Proveedor {$product->sku}",
            'document_type' => Supplier::DOCUMENT_J,
            'document_number' => "J-{$product->sku}",
        ]);

        $purchase = app(PurchaseOrderService::class)->createDraft($user, [
            'supplier_id' => $supplier->id,
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'items' => [[
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_cost' => 10,
                'serial_units' => $serialUnits,
            ]],
        ]);

        return app(PurchaseOrderService::class)->receive($purchase, $user);
    }

    private function product(Tenant $tenant, string $trackingType, string $sku): array
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
