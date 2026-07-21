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
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPrice;
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
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'pos.order.paid',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => $response->json('data.id'),
            'status' => 'pending',
        ]);
    }

    public function test_pos_credit_checkout_confirms_sale_and_leaves_receivable_balance(): void
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
        $customer = $this->customer($tenant, 'Cliente Credito', Customer::DOCUMENT_V, '777');

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $session->id,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'credit' => true,
                'credit_due_date' => '2026-08-01',
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                ]],
                'payments' => [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 50,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID)
            ->assertJsonPath('data.sale.status', Sale::STATUS_CONFIRMED)
            ->assertJsonPath('data.paid_base_amount', '50.0000');

        $this->assertDatabaseHas('accounts_receivables', [
            'tenant_id' => $tenant->id,
            'sale_id' => $response->json('data.sale_id'),
            'status' => AccountsReceivable::STATUS_PARTIAL,
            'collected_base_amount' => '50.0000',
            'balance_base_amount' => '150.0000',
            'due_date' => '2026-08-01',
        ]);
        $this->assertDatabaseHas('cash_register_movements', [
            'tenant_id' => $tenant->id,
            'cash_register_session_id' => $session->id,
            'type' => CashRegisterMovement::TYPE_POS_PAYMENT,
            'amount_base' => '50.0000',
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '3.0000',
        ]);
    }

    public function test_pos_credit_checkout_requires_registered_customer(): void
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

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $session->id,
                'credit' => true,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
                'payments' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);
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

    public function test_pos_checkout_applies_line_discount_and_stores_audit_data(): void
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

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $session->id,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'discount_type' => 'percent',
                    'discount_value' => 10,
                    'discount_reason' => 'Promocion autorizada',
                ]],
                'payments' => [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 180,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID)
            ->assertJsonPath('data.total_base_amount', '180.0000')
            ->assertJsonPath('data.sale.total_base_amount', 180)
            ->assertJsonPath('data.sale.items.0.discount_type', 'percent')
            ->assertJsonPath('data.sale.items.0.discount_value', 10)
            ->assertJsonPath('data.sale.items.0.discount_base_amount', 20)
            ->assertJsonPath('data.sale.items.0.discount_reason', 'Promocion autorizada');

        $this->assertDatabaseHas('sale_items', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'discount_type' => 'percent',
            'discount_value' => '10.0000',
            'discount_amount' => '20.0000',
            'discount_base_amount' => '20.0000',
            'base_total_amount' => '180.0000',
            'discount_reason' => 'Promocion autorizada',
        ]);
    }

    public function test_pos_checkout_rejects_payment_method_not_allowed_by_price_list(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 2,
        ]);
        $priceList = $this->priceListWithPrice($tenant, $product, 'Detal Divisa', 'DETAL-USD', 100);
        $usdCash = PaymentMethod::create([
            'name' => 'Efectivo USD',
            'code' => 'CASH-USD',
            'method' => PosPayment::METHOD_CASH,
            'currency_mode' => PaymentMethod::CURRENCY_USD,
        ]);
        $vesMobile = PaymentMethod::create([
            'name' => 'Pago movil Bs',
            'code' => 'PM-VES',
            'method' => PosPayment::METHOD_MOBILE_PAYMENT,
            'currency_mode' => PaymentMethod::CURRENCY_VES,
        ]);
        $priceList->paymentMethods()->sync([$usdCash->id => ['tenant_id' => $tenant->id]]);

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
                    'price_list_id' => $priceList->id,
                    'quantity' => 1,
                ]],
                'payments' => [[
                    'payment_method_id' => $vesMobile->id,
                    'method' => PosPayment::METHOD_MOBILE_PAYMENT,
                    'currency' => Product::CURRENCY_VES,
                    'amount' => 50000,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payments.0.payment_method_id']);
    }

    public function test_pos_checkout_accepts_flexible_price_list_with_mixed_payments(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product, $rateType] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 2,
        ]);
        $priceList = $this->priceListWithPrice($tenant, $product, 'Detal Flexible', 'DETAL-FLEX', 100);
        $cash = PaymentMethod::create([
            'name' => 'Efectivo flexible',
            'code' => 'CASH-FLEX',
            'method' => PosPayment::METHOD_CASH,
            'currency_mode' => PaymentMethod::CURRENCY_FLEXIBLE,
        ]);
        $mobile = PaymentMethod::create([
            'name' => 'Pago movil Bs',
            'code' => 'PM-FLEX',
            'method' => PosPayment::METHOD_MOBILE_PAYMENT,
            'currency_mode' => PaymentMethod::CURRENCY_VES,
            'requires_reference' => true,
        ]);
        $priceList->paymentMethods()->sync([
            $cash->id => ['tenant_id' => $tenant->id],
            $mobile->id => ['tenant_id' => $tenant->id],
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
                    'price_list_id' => $priceList->id,
                    'quantity' => 1,
                ]],
                'payments' => [
                    [
                        'payment_method_id' => $cash->id,
                        'method' => PosPayment::METHOD_CASH,
                        'currency' => Product::CURRENCY_USD,
                        'amount' => 40,
                    ],
                    [
                        'payment_method_id' => $mobile->id,
                        'method' => PosPayment::METHOD_MOBILE_PAYMENT,
                        'currency' => Product::CURRENCY_VES,
                        'amount' => 30000,
                        'exchange_rate_type_id' => $rateType->id,
                        'reference' => 'PM-MIX-001',
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID)
            ->assertJsonPath('data.paid_base_amount', '100.0000')
            ->assertJsonPath('data.payments.0.payment_method_id', $cash->id)
            ->assertJsonPath('data.payments.1.payment_method_id', $mobile->id);
    }

    public function test_pos_checkout_closes_usd_sale_with_ves_payment_using_non_default_rate(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        $product->update(['base_price' => 50]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 2,
        ]);

        $parallelRateType = ExchangeRateType::create([
            'code' => 'PARALELO',
            'name' => 'Tasa Paralelo',
            'is_default' => false,
        ]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $parallelRateType->id,
            'rate' => 600,
            'effective_at' => '2026-07-02 13:00:00',
            'is_active' => true,
        ]);

        $cash = PaymentMethod::create([
            'name' => 'Efectivo USD',
            'code' => 'CASH-USD-MIX',
            'method' => PosPayment::METHOD_CASH,
            'currency_mode' => PaymentMethod::CURRENCY_USD,
        ]);
        $mobile = PaymentMethod::create([
            'name' => 'Pago movil paralelo',
            'code' => 'PM-PAR-MIX',
            'method' => PosPayment::METHOD_MOBILE_PAYMENT,
            'currency_mode' => PaymentMethod::CURRENCY_VES,
            'requires_reference' => true,
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
                'payments' => [
                    [
                        'payment_method_id' => $cash->id,
                        'method' => PosPayment::METHOD_CASH,
                        'currency' => Product::CURRENCY_USD,
                        'amount' => 40,
                    ],
                    [
                        'payment_method_id' => $mobile->id,
                        'method' => PosPayment::METHOD_MOBILE_PAYMENT,
                        'currency' => Product::CURRENCY_VES,
                        'amount' => 6000,
                        'exchange_rate_type_id' => $parallelRateType->id,
                        'reference' => 'PM-PAR-001',
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID)
            ->assertJsonPath('data.total_base_amount', '50.0000')
            ->assertJsonPath('data.paid_base_amount', '50.0000')
            ->assertJsonPath('data.payments.1.amount', '6000.0000')
            ->assertJsonPath('data.payments.1.amount_base', '10.0000')
            ->assertJsonPath('data.payments.1.amount_local', '6000.0000')
            ->assertJsonPath('data.payments.1.exchange_rate_type_code', 'PARALELO')
            ->assertJsonPath('data.payments.1.exchange_rate', '600.000000');

        $this->assertDatabaseHas('cash_register_movements', [
            'tenant_id' => $tenant->id,
            'cash_register_session_id' => $session->id,
            'type' => CashRegisterMovement::TYPE_POS_PAYMENT,
            'method' => PosPayment::METHOD_MOBILE_PAYMENT,
            'currency' => Product::CURRENCY_VES,
            'amount' => 6000,
            'amount_base' => 10,
            'amount_local' => 6000,
            'exchange_rate_type_code' => 'PARALELO',
            'exchange_rate' => 600,
            'reference' => 'PM-PAR-001',
        ]);
    }

    public function test_pos_checkout_rejects_inactive_payment_method_for_restricted_price_list(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 2,
        ]);
        $priceList = $this->priceListWithPrice($tenant, $product, 'Tecnico', 'TEC', 100);
        $inactiveCash = PaymentMethod::create([
            'name' => 'Efectivo USD inactivo',
            'code' => 'CASH-USD-OFF',
            'method' => PosPayment::METHOD_CASH,
            'currency_mode' => PaymentMethod::CURRENCY_USD,
            'is_active' => false,
        ]);
        $priceList->paymentMethods()->sync([$inactiveCash->id => ['tenant_id' => $tenant->id]]);

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
                    'price_list_id' => $priceList->id,
                    'quantity' => 1,
                ]],
                'payments' => [[
                    'payment_method_id' => $inactiveCash->id,
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payments.0.payment_method_id']);
    }

    public function test_pos_checkout_rejects_price_list_without_payment_methods(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 2,
        ]);
        $priceList = $this->priceListWithPrice($tenant, $product, 'Mayor sin metodos', 'MAYOR-SIN', 100);

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
                    'price_list_id' => $priceList->id,
                    'quantity' => 1,
                ]],
                'payments' => [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payments'])
            ->assertJsonPath('errors.payments.0', 'La lista de precio Mayor sin metodos no tiene metodos de pago configurados para POS.');
    }

    public function test_pos_checkout_rejects_selected_price_list_without_product_price(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 2,
        ]);
        $this->useTenant($tenant);
        $priceList = PriceList::create([
            'name' => 'Precio mayor',
            'code' => 'MAYOR',
        ]);
        $cash = PaymentMethod::create([
            'name' => 'Efectivo USD',
            'code' => 'CASH-MAYOR',
            'method' => PosPayment::METHOD_CASH,
            'currency_mode' => PaymentMethod::CURRENCY_USD,
        ]);
        $priceList->paymentMethods()->sync([$cash->id => ['tenant_id' => $tenant->id]]);
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
                    'price_list_id' => $priceList->id,
                    'quantity' => 1,
                ]],
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price_list_id'])
            ->assertJsonPath('errors.price_list_id.0', 'Este producto no tiene precio en esta lista.');
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
            'quantity_available' => '1.0000',
            'quantity_reserved' => '1.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'reserved',
            'quantity' => '1.0000',
            'reference_type' => PosOrder::class,
        ]);
        $this->assertDatabaseCount('cash_register_movements', 0);
        $this->assertDatabaseCount('accounts_receivables', 0);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
    }

    public function test_pending_pos_order_can_be_completed_with_captured_payment(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 2,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['pos.checkout', 'pos.view']);
        $session = $this->cashRegisterSession($tenant, $user, $warehouse->branch_id);

        $checkout = $this
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
                    'method' => PosPayment::METHOD_TRANSFER,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                    'status' => PosPayment::STATUS_PENDING,
                    'reference' => 'TRX-PEND-001',
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_OPEN)
            ->assertJsonPath('data.sale.status', Sale::STATUS_DRAFT);

        $orderId = $checkout->json('data.id');

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '1.0000',
            'quantity_reserved' => '1.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'reserved',
            'reference_type' => PosOrder::class,
            'reference_id' => $orderId,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$orderId}/payments", [
                'payments' => [[
                    'method' => PosPayment::METHOD_TRANSFER,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                    'status' => PosPayment::STATUS_CAPTURED,
                    'reference' => 'TRX-CAPT-001',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID)
            ->assertJsonPath('data.sale.status', Sale::STATUS_CONFIRMED)
            ->assertJsonPath('data.paid_base_amount', '100.0000')
            ->assertJsonCount(2, 'data.payments');

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '1.0000',
            'quantity_reserved' => '0.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'released',
            'reference_type' => PosOrder::class,
            'reference_id' => $orderId,
        ]);
        $this->assertDatabaseHas('cash_register_movements', [
            'tenant_id' => $tenant->id,
            'cash_register_session_id' => $session->id,
            'type' => CashRegisterMovement::TYPE_POS_PAYMENT,
            'method' => PosPayment::METHOD_TRANSFER,
            'amount_base' => '100.0000',
        ]);
        $this->assertDatabaseHas('accounts_receivables', [
            'tenant_id' => $tenant->id,
            'sale_id' => $checkout->json('data.sale_id'),
            'status' => AccountsReceivable::STATUS_PAID,
            'collected_base_amount' => '100.0000',
            'balance_base_amount' => '0.0000',
        ]);
    }

    public function test_pending_pos_order_reserves_last_unit_and_blocks_another_cashier(): void
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

        $this
            ->actingAs($cashierA)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $sessionA->id,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
                'payments' => [[
                    'method' => PosPayment::METHOD_TRANSFER,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 50,
                    'status' => PosPayment::STATUS_CAPTURED,
                    'reference' => 'TRX-PARTIAL-001',
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_OPEN);

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '0.0000',
            'quantity_reserved' => '1.0000',
        ]);

        $this
            ->actingAs($cashierB)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $sessionB->id,
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
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock']);

        $this->assertDatabaseCount('pos_orders', 1);
    }

    public function test_pending_pos_order_reserves_serialized_imei_until_completed(): void
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
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['pos.checkout']);
        $session = $this->cashRegisterSession($tenant, $user, $warehouse->branch_id);

        $checkout = $this
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
                    'method' => PosPayment::METHOD_TRANSFER,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 50,
                    'status' => PosPayment::STATUS_CAPTURED,
                    'reference' => 'TRX-IMEI-PARTIAL',
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_OPEN);

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $unit->id,
            'status' => ProductUnit::STATUS_RESERVED,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '0.0000',
            'quantity_reserved' => '1.0000',
        ]);

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
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_unit_ids']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$checkout->json('data.id')}/payments", [
                'payments' => [[
                    'method' => PosPayment::METHOD_TRANSFER,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 50,
                    'status' => PosPayment::STATUS_CAPTURED,
                    'reference' => 'TRX-IMEI-CLOSE',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID);

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

    public function test_pending_pos_order_can_be_cancelled_and_releases_serialized_imei(): void
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
        $this->grantRole($tenant, $user, 'Cajero', ['pos.checkout', 'pos.cancel']);
        $session = $this->cashRegisterSession($tenant, $user, $warehouse->branch_id);

        $checkout = $this
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
                    'method' => PosPayment::METHOD_EXTERNAL_FINANCING,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                    'status' => PosPayment::STATUS_PENDING,
                    'external_provider' => 'Financiadora Demo',
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_OPEN);

        $orderId = $checkout->json('data.id');

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $unit->id,
            'status' => ProductUnit::STATUS_RESERVED,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', PosOrder::STATUS_CANCELLED)
            ->assertJsonPath('data.sale.status', Sale::STATUS_CANCELLED);

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '1.0000',
            'quantity_reserved' => '0.0000',
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $unit->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
            'released_stock_movement_id' => null,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'released',
            'reference_type' => PosOrder::class,
            'reference_id' => $orderId,
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'pos.order.cancelled',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => $orderId,
            'status' => 'pending',
        ]);
    }

    public function test_pending_pos_order_with_captured_payment_cannot_be_cancelled_without_refund(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 1,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['pos.checkout', 'pos.cancel']);
        $session = $this->cashRegisterSession($tenant, $user, $warehouse->branch_id);

        $checkout = $this
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
                    'amount' => 50,
                    'status' => PosPayment::STATUS_CAPTURED,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_OPEN);

        $orderId = $checkout->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$orderId}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payments']);

        $this->assertDatabaseHas('pos_orders', [
            'tenant_id' => $tenant->id,
            'id' => $orderId,
            'status' => PosOrder::STATUS_OPEN,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '0.0000',
            'quantity_reserved' => '1.0000',
        ]);
    }

    public function test_pending_pos_order_rejects_serialized_product_without_selected_imei(): void
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
                    'method' => PosPayment::METHOD_TRANSFER,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 50,
                    'status' => PosPayment::STATUS_CAPTURED,
                    'reference' => 'TRX-IMEI-MISSING',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items'])
            ->assertJsonPath('errors.items.0', 'Los productos serializados requieren seleccionar un IMEI o serial por cada unidad vendida.');

        $this->assertDatabaseCount('pos_orders', 0);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '1.0000',
            'quantity_reserved' => '0.0000',
        ]);
    }

    public function test_pending_pos_order_rejects_repeated_serialized_imei(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500, Product::TRACKING_SERIALIZED);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 2,
        ]);
        $unit = ProductUnit::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860003333333333',
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
                    'quantity' => 2,
                    'product_unit_ids' => [$unit->id, $unit->id],
                ]],
                'payments' => [[
                    'method' => PosPayment::METHOD_TRANSFER,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                    'status' => PosPayment::STATUS_CAPTURED,
                    'reference' => 'TRX-IMEI-DUP',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items'])
            ->assertJsonPath('errors.items.0', 'No se puede repetir el mismo IMEI o serial en una orden POS.');

        $this->assertDatabaseCount('pos_orders', 0);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $unit->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
    }

    public function test_pending_pos_order_rejects_serialized_imei_from_another_warehouse(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500, Product::TRACKING_SERIALIZED);
        $this->useTenant($tenant);
        $otherWarehouse = Warehouse::create([
            'branch_id' => $warehouse->branch_id,
            'name' => 'Almacen secundario',
            'code' => 'WH-POS-SEC-'.$tenant->id,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 1,
        ]);
        $unit = ProductUnit::create([
            'warehouse_id' => $otherWarehouse->id,
            'product_id' => $product->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860004444444444',
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
                    'method' => PosPayment::METHOD_TRANSFER,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 50,
                    'status' => PosPayment::STATUS_CAPTURED,
                    'reference' => 'TRX-IMEI-WH',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->assertDatabaseCount('pos_orders', 0);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $unit->id,
            'warehouse_id' => $otherWarehouse->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
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

        $this->useTenant($tenant);
        $legacySession = CashRegisterSession::create([
            'branch_id' => $warehouse->branch_id,
            'cashier_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);
        $payload['cash_register_session_id'] = $legacySession->id;

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cash_register_session_id'])
            ->assertJsonPath('errors.cash_register_session_id.0', 'Abre un turno en una caja fisica activa antes de vender en POS.');

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

    private function priceListWithPrice(Tenant $tenant, Product $product, string $name, string $code, float $price): PriceList
    {
        $this->useTenant($tenant);

        $priceList = PriceList::create([
            'name' => $name,
            'code' => $code,
            'is_active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'price_list_id' => $priceList->id,
            'price' => $price,
            'currency' => Product::CURRENCY_USD,
            'is_active' => true,
        ]);

        return $priceList;
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
            'code' => 'CJ-'.$cashier->id.'-'.strtoupper(substr((string) str()->uuid(), 0, 6)),
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

    public function test_checkout_with_idempotency_key_returns_same_response_on_retry(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Idem', 'slug' => 'empresa-idem']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 5,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero Idem', ['pos.checkout', 'pos.view']);
        $session = $this->cashRegisterSession($tenant, $user, $warehouse->branch_id);
        $customer = $this->customer($tenant, 'Cliente Idem', Customer::DOCUMENT_V, '555');

        $payload = [
            'cash_register_session_id' => $session->id,
            'customer_id' => $customer->id,
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
        ];
        $idemKey = 'idem-test-' . uniqid();

        $first = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('Idempotency-Key', $idemKey)
            ->postJson('/api/pos/checkouts', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID);
        $firstOrderId = $first->json('data.id');

        // Segundo POST con la misma key y mismo body: el middleware
        // debe devolver la misma respuesta sin ejecutar el servicio.
        $second = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('Idempotency-Key', $idemKey)
            ->postJson('/api/pos/checkouts', $payload)
            ->assertCreated();
        $this->assertSame($firstOrderId, $second->json('data.id'));

        // Solo se creo una venta, no dos.
        $this->assertSame(1, PosOrder::query()->where('cash_register_session_id', $session->id)->count());
    }

    public function test_checkout_with_same_idempotency_key_different_body_returns_409(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Idem2', 'slug' => 'empresa-idem-2']);
        [$warehouse, $product] = $this->pricedProduct($tenant, Product::CURRENCY_USD, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 10,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero Idem2', ['pos.checkout', 'pos.view']);
        $session = $this->cashRegisterSession($tenant, $user, $warehouse->branch_id);

        $idemKey = 'idem-conflict-' . uniqid();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('Idempotency-Key', $idemKey)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $session->id,
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
            ->assertCreated();

        // Misma key pero distinto body: 409.
        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->withHeader('Idempotency-Key', $idemKey)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $session->id,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 3,
                ]],
                'payments' => [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 300,
                ]],
            ])
            ->assertStatus(409);
    }
}
