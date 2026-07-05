<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
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

class OperationalTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_products_cash_registers_and_pos_sales_are_isolated_by_company(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa Caracas', 'slug' => 'empresa-caracas']);
        $tenantB = Tenant::create(['name' => 'Empresa Valencia', 'slug' => 'empresa-valencia']);

        [$userA, $warehouseA, $productA, $sessionA] = $this->operationalCompany(
            tenant: $tenantA,
            userEmail: 'cajero.caracas@demo.test',
            productName: 'Telefono Caracas A06',
            sku: 'SKU-COMPARTIDO',
            stock: 5
        );

        [$userB, $warehouseB, $productB, $sessionB] = $this->operationalCompany(
            tenant: $tenantB,
            userEmail: 'cajero.valencia@demo.test',
            productName: 'Telefono Valencia A06',
            sku: 'SKU-COMPARTIDO',
            stock: 8
        );

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/inventory-center/summary?stock_status=all')
            ->assertOk()
            ->assertJsonPath('data.metrics.total_products', 1)
            ->assertJsonPath('data.products.0.name', 'Telefono Caracas A06')
            ->assertJsonMissing(['name' => 'Telefono Valencia A06']);

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson('/api/inventory-center/summary?stock_status=all')
            ->assertOk()
            ->assertJsonPath('data.metrics.total_products', 1)
            ->assertJsonPath('data.products.0.name', 'Telefono Valencia A06')
            ->assertJsonMissing(['name' => 'Telefono Caracas A06']);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson('/api/inventory-center/summary?stock_status=all')
            ->assertForbidden();

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $sessionB->id,
                'items' => [[
                    'warehouse_id' => $warehouseA->id,
                    'product_id' => $productA->id,
                    'quantity' => 1,
                ]],
                'payments' => [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cash_register_session_id']);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $sessionA->id,
                'items' => [[
                    'warehouse_id' => $warehouseB->id,
                    'product_id' => $productB->id,
                    'quantity' => 1,
                ]],
                'payments' => [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.warehouse_id', 'items.0.product_id']);

        $this->sellProduct($tenantA, $userA, $sessionA, $warehouseA, $productA);
        $this->sellProduct($tenantB, $userB, $sessionB, $warehouseB, $productB);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/pos/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sale.items.0.product_name', 'Telefono Caracas A06')
            ->assertJsonMissing(['product_name' => 'Telefono Valencia A06']);

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson('/api/pos/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sale.items.0.product_name', 'Telefono Valencia A06')
            ->assertJsonMissing(['product_name' => 'Telefono Caracas A06']);

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenantA->id,
            'product_id' => $productA->id,
            'quantity_available' => '4.0000',
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenantB->id,
            'product_id' => $productB->id,
            'quantity_available' => '7.0000',
        ]);
        $this->assertDatabaseCount('pos_orders', 2);
        $this->assertDatabaseHas('pos_orders', [
            'tenant_id' => $tenantA->id,
            'cash_register_session_id' => $sessionA->id,
            'status' => PosOrder::STATUS_PAID,
        ]);
        $this->assertDatabaseHas('pos_orders', [
            'tenant_id' => $tenantB->id,
            'cash_register_session_id' => $sessionB->id,
            'status' => PosOrder::STATUS_PAID,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function operationalCompany(
        Tenant $tenant,
        string $userEmail,
        string $productName,
        string $sku,
        int $stock
    ): array {
        $this->useTenant($tenant);

        $user = User::factory()->create(['email' => $userEmail]);
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $user, [
            'products.view',
            'inventory.view',
            'pos.view',
            'pos.checkout',
            'cash_register.view',
            'cash_register.open',
        ]);

        $branch = Branch::create([
            'name' => 'Sucursal Principal',
            'code' => 'MAIN',
        ]);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'Almacen Principal',
            'code' => 'WH-MAIN',
        ]);
        $rateType = ExchangeRateType::create([
            'code' => 'BCV',
            'name' => 'Banco Central',
            'is_default' => true,
            'is_active' => true,
        ]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'rate' => 500,
            'effective_at' => '2026-07-05 09:00:00',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => $productName,
            'sku' => $sku,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
            'sale_exchange_rate_type_id' => $rateType->id,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => $stock,
        ]);
        $session = CashRegisterSession::create([
            'branch_id' => $branch->id,
            'cashier_id' => $user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opening_currency' => Product::CURRENCY_USD,
            'opening_base_amount' => 0,
            'opening_local_amount' => 0,
            'expected_base_amount' => 0,
            'expected_local_amount' => 0,
            'opened_at' => now(),
        ]);

        return [$user, $warehouse, $product, $session];
    }

    private function sellProduct(Tenant $tenant, User $user, CashRegisterSession $session, Warehouse $warehouse, Product $product): void
    {
        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $session->id,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
                'payments' => [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID);
    }

    private function grantRole(Tenant $tenant, User $user, array $permissions): void
    {
        $this->useTenant($tenant);

        $role = Role::findOrCreate('Operador '.$tenant->id, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
