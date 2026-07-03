<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PosCheckoutApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_checkout_with_captured_usd_payment_confirms_sale_and_decreases_inventory(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 5,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['pos.checkout', 'pos.view']);
        $session = $this->cashRegisterSession($tenant, $user, $warehouse->branch_id);
        $customer = $this->customer($tenant, 'Cliente POS', Customer::DOCUMENT_V, '555');

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $session->id,
                'customer_id' => $customer->id,
                'customer_name' => 'Cliente mostrador',
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                ]],
                'payments' => [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 200,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID)
            ->assertJsonPath('data.cash_register_session_id', $session->id)
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.customer.name', 'Cliente POS')
            ->assertJsonPath('data.sale.customer_id', $customer->id)
            ->assertJsonPath('data.sale.status', Sale::STATUS_CONFIRMED)
            ->assertJsonPath('data.payments.0.method', PosPayment::METHOD_CASH)
            ->assertJsonPath('data.payments.0.status', PosPayment::STATUS_CAPTURED);

        $this->assertNotNull($response->json('data.paid_at'));
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '3.0000',
        ]);
        $this->assertDatabaseHas('cash_register_movements', [
            'tenant_id' => $tenant->id,
            'cash_register_session_id' => $session->id,
            'type' => CashRegisterMovement::TYPE_POS_PAYMENT,
            'method' => PosPayment::METHOD_CASH,
            'amount_base' => '200.0000',
        ]);
        $this->assertDatabaseHas('accounts_receivables', [
            'tenant_id' => $tenant->id,
            'sale_id' => $response->json('data.sale_id'),
            'status' => AccountsReceivable::STATUS_PAID,
            'collected_base_amount' => '200.0000',
            'balance_base_amount' => '0.0000',
        ]);
        $this->assertDatabaseHas('accounts_receivable_payments', [
            'tenant_id' => $tenant->id,
            'method' => 'pos_cash',
            'amount_base' => '200.0000',
        ]);
    }

    public function test_pos_checkout_with_ves_payment_stores_payment_rate_snapshot(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product, $rateType] = $this->pricedProduct($tenant, Product::CURRENCY_VES, 'PARALELO', 600);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 3,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['pos.checkout']);
        $session = $this->cashRegisterSession($tenant, $user, $warehouse->branch_id);

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
                    'method' => PosPayment::METHOD_MOBILE_PAYMENT,
                    'currency' => Product::CURRENCY_VES,
                    'amount' => 60000,
                    'exchange_rate_type_id' => $rateType->id,
                    'reference' => 'PM-001',
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID)
            ->assertJsonPath('data.paid_base_amount', '100.0000')
            ->assertJsonPath('data.payments.0.amount_base', '100.0000')
            ->assertJsonPath('data.payments.0.amount_local', '60000.0000')
            ->assertJsonPath('data.payments.0.exchange_rate_type_code', 'PARALELO')
            ->assertJsonPath('data.payments.0.exchange_rate', '600.000000');

        $this->assertDatabaseHas('cash_register_movements', [
            'tenant_id' => $tenant->id,
            'cash_register_session_id' => $session->id,
            'type' => CashRegisterMovement::TYPE_POS_PAYMENT,
            'amount_base' => '100.0000',
            'amount_local' => '60000.0000',
            'exchange_rate_type_code' => 'PARALELO',
        ]);
        $this->assertDatabaseHas('accounts_receivable_payments', [
            'tenant_id' => $tenant->id,
            'method' => 'pos_mobile_payment',
            'amount_base' => '100.0000',
            'amount_local' => '60000.0000',
            'exchange_rate_type_code' => 'PARALELO',
        ]);
    }

    public function test_pending_external_financing_keeps_pos_order_open_and_sale_draft(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 2,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['pos.checkout']);
        $session = $this->cashRegisterSession($tenant, $user, $warehouse->branch_id);

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
                    'method' => PosPayment::METHOD_EXTERNAL_FINANCING,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                    'status' => PosPayment::STATUS_PENDING,
                    'external_provider' => 'Financiadora Demo',
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_OPEN)
            ->assertJsonPath('data.sale.status', Sale::STATUS_DRAFT)
            ->assertJsonPath('data.paid_base_amount', '0.0000')
            ->assertJsonPath('data.payments.0.external_provider', 'Financiadora Demo');

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '2.0000',
        ]);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('cash_register_movements', 0);
        $this->assertDatabaseCount('accounts_receivables', 0);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
    }

    public function test_pos_checkout_can_sell_exact_serialized_imei(): void
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
            'serial_number' => '860009999999999',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['pos.checkout']);
        $session = $this->cashRegisterSession($tenant, $user, $warehouse->branch_id);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $session->id,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'product_unit_ids' => [$unit->id],
                ]],
                'payments' => [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID)
            ->assertJsonPath('data.sale.items.0.product_unit_ids.0', $unit->id)
            ->assertJsonPath('data.sale.items.0.serial_units.0.serial_number', '860009999999999');

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $unit->id,
            'status' => ProductUnit::STATUS_SOLD,
        ]);
    }

    public function test_pos_orders_do_not_mix_companies_and_reject_foreign_resources(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];
        [$warehouseA, $productA] = $this->pricedProduct($tenantA, Product::CURRENCY_USD, 'BCV', 500);
        [, $productB] = $this->pricedProduct($tenantB, Product::CURRENCY_USD, 'BCV', 700);
        $customerB = $this->customer($tenantB, 'Cliente B', Customer::DOCUMENT_V, '222');
        $this->useTenant($tenantA);
        StockBalance::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $productA->id,
            'quantity_available' => 2,
        ]);
        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Cajero', ['pos.checkout', 'pos.view']);
        $session = $this->cashRegisterSession($tenantA, $user, $warehouseA->branch_id);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $session->id,
                'customer_id' => $customerB->id,
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
            ->assertJsonValidationErrors(['customer_id']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $session->id,
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
            ->assertCreated();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/pos/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $session->id,
                'items' => [[
                    'warehouse_id' => $warehouseA->id,
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
            ->assertJsonValidationErrors(['items.0.product_id']);
    }

    public function test_pos_checkout_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        $user = $this->userInTenant($tenant);
        $session = $this->cashRegisterSession($tenant, $user, $warehouse->branch_id);

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
            ->assertForbidden();
    }

    public function test_pos_checkout_requires_open_cash_register_owned_by_cashier(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 2,
        ]);
        $cashier = $this->userInTenant($tenant);
        $otherCashier = $this->userInTenant($tenant);
        $this->grantRole($tenant, $cashier, 'Cajero', ['pos.checkout']);

        $otherSession = $this->cashRegisterSession($tenant, $otherCashier, $warehouse->branch_id);

        $payload = [
            'cash_register_session_id' => $otherSession->id,
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
        ];

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cash_register_session_id']);

        $session = $this->cashRegisterSession($tenant, $cashier, $warehouse->branch_id, CashRegisterSession::STATUS_CLOSED);
        $payload['cash_register_session_id'] = $session->id;

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cash_register_session_id']);
    }

    public function test_multiple_cash_registers_cannot_sell_the_last_unit_twice(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 1,
        ]);

        $cashierA = $this->userInTenant($tenant);
        $cashierB = $this->userInTenant($tenant);
        $this->grantRole($tenant, $cashierA, 'Cajero A', ['pos.checkout']);
        $this->grantRole($tenant, $cashierB, 'Cajero B', ['pos.checkout']);
        $sessionA = $this->cashRegisterSession($tenant, $cashierA, $warehouse->branch_id);
        $sessionB = $this->cashRegisterSession($tenant, $cashierB, $warehouse->branch_id);

        $payload = fn (int $sessionId): array => [
            'cash_register_session_id' => $sessionId,
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
        ];

        $this
            ->actingAs($cashierA)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', $payload($sessionA->id))
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID);

        $this
            ->actingAs($cashierB)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', $payload($sessionB->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock']);

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '0.0000',
        ]);
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('cash_register_movements', 1);
        $this->assertDatabaseHas('cash_register_movements', [
            'tenant_id' => $tenant->id,
            'cash_register_session_id' => $sessionA->id,
            'type' => CashRegisterMovement::TYPE_POS_PAYMENT,
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

    private function pricedProduct(Tenant $tenant, string $saleCurrency, string $rateCode, float $rate, string $trackingType = Product::TRACKING_QUANTITY): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => "BR-POS-{$rateCode}-{$tenant->id}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => "WH-POS-{$rateCode}-{$tenant->id}"]);
        $rateType = ExchangeRateType::create(['code' => $rateCode, 'name' => "Tasa {$rateCode}", 'is_default' => true]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'rate' => $rate,
            'effective_at' => '2026-07-02 12:00:00',
            'is_active' => true,
        ]);
        $product = Product::create([
            'name' => "Producto POS {$rateCode}",
            'sku' => "SKU-POS-{$rateCode}-{$tenant->id}",
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

        return CashRegisterSession::create([
            'branch_id' => $branchId,
            'cashier_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'status' => $status,
            'opened_at' => now(),
        ]);
    }

    private function customer(Tenant $tenant, string $name, string $documentType, string $documentNumber): Customer
    {
        $this->useTenant($tenant);

        return Customer::create([
            'name' => $name,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
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
