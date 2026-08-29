<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\ReportZService;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerCreditService;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Modules\SalesReversals\Models\SaleReversal;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PosSaleReversalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_void_paid_sale_and_reverse_stock_and_cash(): void
    {
        [$tenant, $user, $session, $warehouse, $product] = $this->fixture();
        $order = $this->paidOrder($tenant, $user, $session, $warehouse, $product);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/reverse", [
                'type' => 'void',
                'reason' => 'Venta registrada por error',
                'cash_register_session_id' => $session->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'void')
            ->assertJsonPath('data.original_pos_order_id', $order->id);

        $reversalId = $response->json('data.id');

        $this->assertDatabaseHas('pos_orders', [
            'id' => $order->id,
            'status' => PosOrder::STATUS_VOIDED,
        ]);
        $this->assertDatabaseHas('sales', [
            'id' => $order->sale_id,
            'status' => 'voided',
        ]);
        $this->assertDatabaseHas('pos_payments', [
            'pos_order_id' => $order->id,
            'status' => PosPayment::STATUS_CAPTURED,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '1.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'type' => 'sale_reversal',
            'reference_type' => 'sale_reversal',
            'reference_id' => $reversalId,
            'quantity' => '1.0000',
        ]);
        $this->assertDatabaseHas('cash_register_movements', [
            'cash_register_session_id' => $session->id,
            'type' => CashRegisterMovement::TYPE_OUTFLOW,
            'source_type' => 'sale_reversal',
            'source_id' => $reversalId,
            'amount_base' => '100.0000',
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'pos.order.reversed',
            'aggregate_type' => 'pos_order',
            'aggregate_id' => $order->id,
        ]);
        $this->assertSame('0.0000', (string) CashRegisterSession::findOrFail($session->id)->expected_base_amount);
    }

    public function test_same_paid_sale_cannot_be_reversed_twice(): void
    {
        [$tenant, $user, $session, $warehouse, $product] = $this->fixture();
        $order = $this->paidOrder($tenant, $user, $session, $warehouse, $product);
        $payload = [
            'type' => 'void',
            'reason' => 'Error de caja',
            'cash_register_session_id' => $session->id,
        ];

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/reverse", $payload)
            ->assertCreated();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/reverse", $payload)
            ->assertUnprocessable();

        $this->assertDatabaseCount('sale_reversals', 1);
        $this->assertDatabaseCount('cash_register_movements', 2);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_reversal_requires_explicit_permission(): void
    {
        [$tenant, $user, $session, $warehouse, $product] = $this->fixture();
        $order = $this->paidOrder($tenant, $user, $session, $warehouse, $product);
        $user->roles()->firstOrFail()->revokePermissionTo('sales.reverse');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/reverse", [
                'type' => 'void',
                'reason' => 'Sin permiso',
                'cash_register_session_id' => $session->id,
            ])
            ->assertForbidden();
    }

    public function test_sales_report_keeps_original_sale_and_exposes_reversal_adjustment(): void
    {
        [$tenant, $user, $session, $warehouse, $product] = $this->fixture();
        $order = $this->paidOrder($tenant, $user, $session, $warehouse, $product);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/reverse", [
                'type' => 'void',
                'reason' => 'Venta duplicada',
                'cash_register_session_id' => $session->id,
            ])
            ->assertCreated();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/reports/v2/sales_overview?scope=tenant')
            ->assertOk()
            ->assertJsonPath('data.totals.sales_count', 1)
            ->assertJsonPath('data.totals.sales_total', 100)
            ->assertJsonPath('data.totals.reversed_total', 100)
            ->assertJsonPath('data.totals.net_sales_total', 0)
            ->assertJsonPath('data.totals.reversal_count', 1);
    }

    public function test_previous_day_sale_requires_reversal_type_instead_of_void(): void
    {
        [$tenant, $user, $session, $warehouse, $product] = $this->fixture();
        $order = $this->paidOrder($tenant, $user, $session, $warehouse, $product);
        $order->update(['paid_at' => now()->subDay()]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/reverse", [
                'type' => 'void',
                'reason' => 'Venta antigua',
                'cash_register_session_id' => $session->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/reverse", [
                'type' => 'reversal',
                'reason' => 'Venta antigua corregida',
                'cash_register_session_id' => $session->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'reversal');
    }

    public function test_non_cash_payment_is_rejected_atomically_until_external_refund_exists(): void
    {
        [$tenant, $user, $session, $warehouse, $product] = $this->fixture();
        $order = $this->paidOrder($tenant, $user, $session, $warehouse, $product);
        $order->payments()->update(['method' => PosPayment::METHOD_CARD]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/reverse", [
                'type' => 'void',
                'reason' => 'Pago externo pendiente',
                'cash_register_session_id' => $session->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payments');

        $this->assertDatabaseCount('sale_reversals', 0);
        $this->assertDatabaseHas('pos_orders', [
            'id' => $order->id,
            'status' => PosOrder::STATUS_PAID,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '0.0000',
        ]);
    }

    public function test_report_z_keeps_gross_sale_and_exposes_reversal_adjustment(): void
    {
        [$tenant, $user, $session, $warehouse, $product] = $this->fixture();
        $order = $this->paidOrder($tenant, $user, $session, $warehouse, $product);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/reverse", [
                'type' => 'void',
                'reason' => 'Venta duplicada para Reporte Z',
                'cash_register_session_id' => $session->id,
            ])
            ->assertCreated();

        $report = app(ReportZService::class)->build($session->refresh());

        $this->assertSame(1, $report['totals']['orders_count']);
        $this->assertSame(100.0, $report['totals']['paid_base_amount']);
        $this->assertSame(100.0, $report['totals']['reversed_base_amount']);
        $this->assertSame(0.0, $report['totals']['net_paid_base_amount']);
    }

    public function test_reversal_cannot_access_an_order_from_another_tenant(): void
    {
        [$tenant, $user, $session, $warehouse, $product] = $this->fixture();
        $order = $this->paidOrder($tenant, $user, $session, $warehouse, $product);

        $otherTenant = Tenant::create(['name' => 'Empresa Ajena', 'slug' => 'empresa-ajena-'.str()->random(8)]);
        app(TenantManager::class)->set($otherTenant);
        setPermissionsTeamId($otherTenant->id);
        $otherUser = User::factory()->create();
        $otherUser->tenants()->attach($otherTenant, ['status' => 'active']);
        $otherRole = Role::create([
            'name' => 'Reversos Ajeno '.str()->random(8),
            'guard_name' => 'web',
            'team_id' => $otherTenant->id,
        ]);
        $otherRole->syncPermissions(['sales.reverse']);
        $otherUser->assignRole($otherRole);

        $this
            ->actingAs($otherUser)
            ->withHeader('X-Tenant', $otherTenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/reverse", [
                'type' => 'void',
                'reason' => 'Acceso cruzado',
                'cash_register_session_id' => $session->id,
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('pos_orders', [
            'id' => $order->id,
            'status' => PosOrder::STATUS_PAID,
        ]);
    }

    public function test_customer_credit_payment_is_restored_as_credit_on_reversal(): void
    {
        [$tenant, $user, $session, $warehouse, $product] = $this->fixture();
        $customer = Customer::create([
            'name' => 'Cliente Reversión',
            'document_type' => Customer::DOCUMENT_V,
            'document_number' => 'V-REV-001',
            'is_generic' => false,
            'is_active' => true,
        ]);
        app(CustomerCreditService::class)->issue($customer, $user, [
            'currency' => Product::CURRENCY_USD,
            'amount' => 100,
            'amount_base' => 100,
            'amount_local' => 0,
            'source_type' => 'test',
            'source_id' => 1,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $session->id,
                'customer_id' => $customer->id,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
                'payments' => [[
                    'method' => PosPayment::METHOD_CUSTOMER_CREDIT,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 100,
                ]],
            ])
            ->assertCreated();
        $order = PosOrder::findOrFail($response->json('data.id'));

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/pos/orders/{$order->id}/reverse", [
                'type' => 'void',
                'reason' => 'Restaurar saldo a favor',
                'cash_register_session_id' => $session->id,
            ])
            ->assertCreated();

        $this->assertSame(100.0, (float) DB::table('customer_credit_transactions')
            ->where('tenant_id', $tenant->id)
            ->sum('amount_base'));
        $this->assertDatabaseHas('customer_credit_transactions', [
            'customer_id' => $customer->id,
            'type' => 'issued',
            'source_type' => SaleReversal::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Permission::findOrCreate('sales.reverse', 'web');
    }

    private function fixture(): array
    {
        $tenant = Tenant::create(['name' => 'Empresa Reversos', 'slug' => 'empresa-reversos-'.str()->random(8)]);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $role = Role::findOrCreate('Reversos '.str()->random(8), 'web');
        $role->syncPermissions([
            'pos.checkout',
            'pos.view',
            'cash_register.move',
            'sales.reverse',
            'reports.sales.view',
        ]);
        $user->assignRole($role);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-REV-'.str()->random(6)]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Principal', 'code' => 'WH-REV-'.str()->random(6)]);
        $rateType = ExchangeRateType::create([
            'code' => 'BCV-REV',
            'name' => 'BCV Reversos',
            'is_default' => true,
        ]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'rate' => 100,
            'effective_at' => now()->subMinute(),
            'is_active' => true,
        ]);
        $cashRegister = CashRegister::create([
            'branch_id' => $branch->id,
            'name' => 'Caja Principal',
            'code' => 'CJ-REV-'.str()->random(6),
            'status' => CashRegister::STATUS_ACTIVE,
        ]);
        $session = CashRegisterSession::create([
            'branch_id' => $branch->id,
            'cash_register_id' => $cashRegister->id,
            'cashier_id' => $user->id,
            'opened_by' => $user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opening_base_amount' => 0,
            'opening_local_amount' => 0,
            'expected_base_amount' => 0,
            'expected_local_amount' => 0,
            'opened_at' => now(),
        ]);
        $product = Product::create([
            'name' => 'Producto Reversible',
            'sku' => 'REV-'.str()->random(8),
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 1,
        ]);

        return [$tenant, $user, $session, $warehouse, $product];
    }

    private function paidOrder(Tenant $tenant, User $user, CashRegisterSession $session, Warehouse $warehouse, Product $product): PosOrder
    {
        $response = $this
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
            ->assertCreated();

        return PosOrder::findOrFail($response->json('data.id'));
    }
}
