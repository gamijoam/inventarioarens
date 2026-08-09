<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Contrato TDD: flujo "vendedor arma -> cajera cobra".
 *
 * Requisitos (2026-08-09):
 * 1. Un vendedor puede armar una orden (espera) SIN sesion de caja, SIN pagos y
 *    SIN seleccionar IMEI en productos serializados (solo arma la orden).
 * 2. La orden registra `seller_id` (quien armo) para la futura estructura de comisiones.
 * 3. La cajera cobra la orden armada por otro usuario usando SU propia sesion de caja.
 * 4. Al cobrar productos serializados, la cajera asigna los IMEIs (no el vendedor).
 * 5. Separacion de permisos: `pos.orders.hold` (armar) vs `pos.checkout` (cobrar).
 */
class PosHoldOrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_with_hold_permission_arms_order_without_cash_session(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 5,
        ]);
        $seller = $this->userInTenant($tenant);
        $this->grantRole($tenant, $seller, 'Vendedor', ['pos.orders.hold', 'pos.view']);

        $response = $this
            ->actingAs($seller)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders', [
                'customer_name' => 'Cliente mostrador',
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_OPEN)
            ->assertJsonPath('data.sale.status', Sale::STATUS_DRAFT)
            ->assertJsonPath('data.cash_register_session_id', null)
            ->assertJsonPath('data.seller_id', $seller->id);

        $this->assertDatabaseHas('pos_orders', [
            'tenant_id' => $tenant->id,
            'id' => $response->json('data.id'),
            'status' => PosOrder::STATUS_OPEN,
            'seller_id' => $seller->id,
            'cash_register_session_id' => null,
            'cashier_id' => null,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '3.0000',
            'quantity_reserved' => '2.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'reserved',
            'reference_type' => PosOrder::class,
            'reference_id' => $response->json('data.id'),
        ]);
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('cash_register_movements', 0);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'pos.order.pending',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => $response->json('data.id'),
        ]);
    }

    public function test_seller_without_hold_permission_cannot_arm_order(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 5,
        ]);
        $seller = $this->userInTenant($tenant);
        $this->grantRole($tenant, $seller, 'Vendedor', ['pos.view']);

        $this
            ->actingAs($seller)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders', [
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_seller_with_hold_permission_cannot_charge_order(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 5,
        ]);
        $seller = $this->userInTenant($tenant);
        $this->grantRole($tenant, $seller, 'Vendedor', ['pos.orders.hold', 'pos.view']);
        $session = $this->cashRegisterSession($tenant, $seller, $warehouse->branch_id);

        $orderId = $this->armedOrderId($tenant, $seller, $warehouse, $product, 1);

        $this
            ->actingAs($seller)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$orderId}/payments", [
                'cash_register_session_id' => $session->id,
                'payments' => [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                ]],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('pos_orders', [
            'tenant_id' => $tenant->id,
            'id' => $orderId,
            'status' => PosOrder::STATUS_OPEN,
        ]);
    }

    public function test_cashier_charges_sellers_order_with_own_cash_session(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 5,
        ]);
        $seller = $this->userInTenant($tenant);
        $this->grantRole($tenant, $seller, 'Vendedor', ['pos.orders.hold', 'pos.view']);
        $cashier = $this->userInTenant($tenant);
        $this->grantRole($tenant, $cashier, 'Cajera', ['pos.checkout', 'pos.view']);
        $cashierSession = $this->cashRegisterSession($tenant, $cashier, $warehouse->branch_id);

        $orderId = $this->armedOrderId($tenant, $seller, $warehouse, $product, 2);

        $response = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$orderId}/payments", [
                'cash_register_session_id' => $cashierSession->id,
                'payments' => [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 200,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID)
            ->assertJsonPath('data.sale.status', Sale::STATUS_CONFIRMED)
            ->assertJsonPath('data.seller_id', $seller->id)
            ->assertJsonPath('data.cashier_id', $cashier->id)
            ->assertJsonPath('data.cash_register_session_id', $cashierSession->id);

        $this->assertDatabaseHas('cash_register_movements', [
            'tenant_id' => $tenant->id,
            'cash_register_session_id' => $cashierSession->id,
            'type' => CashRegisterMovement::TYPE_POS_PAYMENT,
            'method' => PosPayment::METHOD_CASH,
            'amount_base' => '200.0000',
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '3.0000',
            'quantity_reserved' => '0.0000',
        ]);
        $this->assertDatabaseHas('accounts_receivables', [
            'tenant_id' => $tenant->id,
            'sale_id' => $response->json('data.sale_id'),
            'status' => AccountsReceivable::STATUS_PAID,
            'collected_base_amount' => '200.0000',
            'balance_base_amount' => '0.0000',
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'pos.order.paid',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => $orderId,
        ]);
    }

    public function test_cashier_cannot_charge_sellers_order_with_foreign_cash_session(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 5,
        ]);
        $seller = $this->userInTenant($tenant);
        $this->grantRole($tenant, $seller, 'Vendedor', ['pos.orders.hold', 'pos.view']);
        $cashier = $this->userInTenant($tenant);
        $this->grantRole($tenant, $cashier, 'Cajera', ['pos.checkout']);
        $otherCashier = $this->userInTenant($tenant);
        $otherSession = $this->cashRegisterSession($tenant, $otherCashier, $warehouse->branch_id);

        $orderId = $this->armedOrderId($tenant, $seller, $warehouse, $product, 1);

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$orderId}/payments", [
                'cash_register_session_id' => $otherSession->id,
                'payments' => [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cash_register_session_id']);

        $this->assertDatabaseHas('pos_orders', [
            'tenant_id' => $tenant->id,
            'id' => $orderId,
            'status' => PosOrder::STATUS_OPEN,
        ]);
    }

    public function test_seller_arms_serialized_order_without_selecting_imei(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500, Product::TRACKING_SERIALIZED);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 1,
        ]);
        $unit = ProductUnit::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860001111111111',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $seller = $this->userInTenant($tenant);
        $this->grantRole($tenant, $seller, 'Vendedor', ['pos.orders.hold', 'pos.view']);

        $response = $this
            ->actingAs($seller)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders', [
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_OPEN);

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $unit->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
            'released_stock_movement_id' => null,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '0.0000',
            'quantity_reserved' => '1.0000',
        ]);
    }

    public function test_cashier_charging_serialized_hold_requires_imei_assignment(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500, Product::TRACKING_SERIALIZED);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 1,
        ]);
        ProductUnit::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860002222222222',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $seller = $this->userInTenant($tenant);
        $this->grantRole($tenant, $seller, 'Vendedor', ['pos.orders.hold', 'pos.view']);
        $cashier = $this->userInTenant($tenant);
        $this->grantRole($tenant, $cashier, 'Cajera', ['pos.checkout']);
        $cashierSession = $this->cashRegisterSession($tenant, $cashier, $warehouse->branch_id);

        $orderId = $this->armedOrderId($tenant, $seller, $warehouse, $product, 1);

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$orderId}/payments", [
                'cash_register_session_id' => $cashierSession->id,
                'payments' => [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->assertDatabaseHas('pos_orders', [
            'tenant_id' => $tenant->id,
            'id' => $orderId,
            'status' => PosOrder::STATUS_OPEN,
        ]);
    }

    public function test_cashier_charges_serialized_hold_assigning_imei(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500, Product::TRACKING_SERIALIZED);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 1,
        ]);
        $unit = ProductUnit::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860003333333333',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $seller = $this->userInTenant($tenant);
        $this->grantRole($tenant, $seller, 'Vendedor', ['pos.orders.hold', 'pos.view']);
        $cashier = $this->userInTenant($tenant);
        $this->grantRole($tenant, $cashier, 'Cajera', ['pos.checkout']);
        $cashierSession = $this->cashRegisterSession($tenant, $cashier, $warehouse->branch_id);

        $orderId = $this->armedOrderId($tenant, $seller, $warehouse, $product, 1);
        $saleId = PosOrder::query()->findOrFail($orderId)->sale_id;
        $saleItemId = SaleItem::query()->where('sale_id', $saleId)->firstOrFail()->id;

        $response = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$orderId}/payments", [
                'cash_register_session_id' => $cashierSession->id,
                'items' => [[
                    'sale_item_id' => $saleItemId,
                    'product_unit_ids' => [$unit->id],
                ]],
                'payments' => [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID)
            ->assertJsonPath('data.sale.status', Sale::STATUS_CONFIRMED)
            ->assertJsonPath('data.seller_id', $seller->id)
            ->assertJsonPath('data.cashier_id', $cashier->id);

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $unit->id,
            'status' => ProductUnit::STATUS_SOLD,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '0.0000',
            'quantity_reserved' => '0.0000',
        ]);
    }

    public function test_held_orders_do_not_leak_across_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        [$warehouseA, $productA] = $this->pricedProduct($tenantA, Product::CURRENCY_USD, 'BCV', 500);
        [$warehouseB, $productB] = $this->pricedProduct($tenantB, Product::CURRENCY_USD, 'BCV', 700);
        $this->useTenant($tenantA);
        StockBalance::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $productA->id,
            'quantity_available' => 5,
        ]);
        $this->useTenant($tenantB);
        StockBalance::create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $productB->id,
            'quantity_available' => 5,
        ]);
        $sellerA = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $sellerA, 'Vendedor A', ['pos.orders.hold', 'pos.view']);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantB, $userB, 'Vendedor B', ['pos.orders.hold', 'pos.view']);

        $this
            ->actingAs($sellerA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/pos/orders', [
                'items' => [[
                    'warehouse_id' => $warehouseA->id,
                    'product_id' => $productA->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated();

        // El index no filtra ni expone ordenes de otro tenant.
        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson('/api/pos/orders?status=open')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Un vendedor de B no puede armar/operar con recursos de A.
        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->postJson('/api/pos/orders', [
                'items' => [[
                    'warehouse_id' => $warehouseA->id,
                    'product_id' => $productA->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.warehouse_id']);
    }

    public function test_pos_orders_table_has_seller_column_accessible(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $this->useTenant($tenant);
        $seller = $this->userInTenant($tenant);

        $this->assertTrue(Schema::hasColumn('pos_orders', 'seller_id'));

        $order = new PosOrder;
        $order->seller_id = $seller->id;

        $this->assertSame($seller->id, $order->seller_id);
        $this->assertTrue(method_exists($order, 'seller'));
    }

    public function test_cashier_can_cancel_sellers_held_order_without_cash_session(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 5,
        ]);
        $seller = $this->userInTenant($tenant);
        $this->grantRole($tenant, $seller, 'Vendedor', ['pos.orders.hold', 'pos.view']);
        $cashier = $this->userInTenant($tenant);
        $this->grantRole($tenant, $cashier, 'Cajera', ['pos.view', 'pos.cancel']);

        $orderId = $this->armedOrderId($tenant, $seller, $warehouse, $product, 2);

        // La orden armada por el vendedor no tiene cash_register_session_id.
        $this->assertDatabaseHas('pos_orders', [
            'tenant_id' => $tenant->id,
            'id' => $orderId,
            'cash_register_session_id' => null,
            'status' => PosOrder::STATUS_OPEN,
        ]);

        $response = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', PosOrder::STATUS_CANCELLED)
            ->assertJsonPath('data.sale.status', Sale::STATUS_CANCELLED);

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '5.0000',
            'quantity_reserved' => '0.0000',
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'pos.order.cancelled',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => $orderId,
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

    private function armedOrderId(Tenant $tenant, User $seller, Warehouse $warehouse, Product $product, int $quantity): int
    {
        $response = $this
            ->actingAs($seller)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/orders', [
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                ]],
            ])
            ->assertCreated();

        return (int) $response->json('data.id');
    }

    private function pricedProduct(Tenant $tenant, string $saleCurrency, string $rateCode, float $rate, string $trackingType = Product::TRACKING_QUANTITY): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => "BR-HOLD-{$rateCode}-{$tenant->id}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => "WH-HOLD-{$rateCode}-{$tenant->id}"]);
        $rateType = ExchangeRateType::create(['code' => $rateCode, 'name' => "Tasa {$rateCode}", 'is_default' => true]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'rate' => $rate,
            'effective_at' => '2026-07-02 12:00:00',
            'is_active' => true,
        ]);
        $product = Product::create([
            'name' => "Producto Hold {$rateCode}",
            'sku' => "SKU-HOLD-{$rateCode}-{$tenant->id}",
            'tracking_type' => $trackingType,
            'base_price' => 100,
            'sale_currency' => $saleCurrency,
            'sale_exchange_rate_type_id' => $rateType->id,
        ]);

        return [$warehouse, $product, $rateType];
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function cashRegisterSession(Tenant $tenant, User $cashier, int $branchId, string $status = CashRegisterSession::STATUS_OPEN): CashRegisterSession
    {
        $this->useTenant($tenant);

        $cashRegister = CashRegister::create([
            'branch_id' => $branchId,
            'name' => 'Caja '.$cashier->id,
            'code' => 'CJ-HOLD-'.$cashier->id.'-'.strtoupper(substr((string) str()->uuid(), 0, 6)),
            'status' => CashRegister::STATUS_ACTIVE,
        ]);

        return CashRegisterSession::create([
            'branch_id' => $branchId,
            'cash_register_id' => $cashRegister->id,
            'cashier_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'status' => $status,
            'opened_at' => now(),
        ]);
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
