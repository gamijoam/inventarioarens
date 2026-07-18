<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Customers\Models\Customer;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OperationalReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_operations_include_open_cash_sessions_without_closing(): void
    {
        Carbon::setTestNow('2026-07-18 11:00:00');

        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant, ['reports.view']);
        $this->seedOperationalData($tenant, $user, 125);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/reports/daily-operations?date=2026-07-18')
            ->assertOk()
            ->assertJsonPath('data.sales.confirmed_count', 1)
            ->assertJsonPath('data.sales.pos_paid_base_amount', 125)
            ->assertJsonPath('data.sales.credit_balance_base_amount', 25)
            ->assertJsonPath('data.returns.requested_count', 1)
            ->assertJsonPath('data.cash.open_count', 1)
            ->assertJsonPath('data.cash.closed_count', 0)
            ->assertJsonPath('data.cash.expected_base_amount', 175)
            ->assertJsonPath('data.payment_methods.0.amount_base', 125);
    }

    public function test_sales_detail_returns_items_payments_receivables_and_returns(): void
    {
        Carbon::setTestNow('2026-07-18 11:00:00');

        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant, ['reports.sales.view']);
        $sale = $this->seedOperationalData($tenant, $user, 125);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/reports/sales-detail?date=2026-07-18')
            ->assertOk()
            ->assertJsonPath('data.rows.0.id', $sale->id)
            ->assertJsonPath('data.rows.0.collection.status', AccountsReceivable::STATUS_PARTIAL)
            ->assertJsonPath('data.rows.0.items.0.product_name', 'Producto Reporte')
            ->assertJsonPath('data.rows.0.payments.0.reference', 'REF-REPORT')
            ->assertJsonPath('data.rows.0.returns.0.status', SalesReturn::STATUS_REQUESTED);
    }

    public function test_cash_sessions_report_marks_open_session_difference_as_pending(): void
    {
        Carbon::setTestNow('2026-07-18 11:00:00');

        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant, ['reports.cash.view']);
        $this->seedOperationalData($tenant, $user, 125);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/reports/cash-sessions?date=2026-07-18&status=open')
            ->assertOk()
            ->assertJsonPath('data.summary.open_count', 1)
            ->assertJsonPath('data.rows.0.status', CashRegisterSession::STATUS_OPEN)
            ->assertJsonPath('data.rows.0.difference_base_amount', null)
            ->assertJsonPath('data.movement_breakdown.0.amount_base', 50);
    }

    public function test_operational_reports_do_not_mix_tenants(): void
    {
        Carbon::setTestNow('2026-07-18 11:00:00');

        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA, ['reports.view']);
        $userB = $this->userInTenant($tenantB, ['reports.view']);

        $this->seedOperationalData($tenantA, $userA, 125);
        $this->seedOperationalData($tenantB, $userB, 999);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/reports/daily-operations?date=2026-07-18')
            ->assertOk()
            ->assertJsonPath('data.sales.pos_paid_base_amount', 125);
    }

    public function test_granular_permissions_block_other_report_modules(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant, ['reports.sales.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/reports/cash-sessions')
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

    private function seedOperationalData(Tenant $tenant, User $user, float $paidTotal): Sale
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-'.$tenant->id]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Tienda', 'code' => 'WH-'.$tenant->id]);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja Principal', 'code' => 'CJ-'.$tenant->id]);
        $session = CashRegisterSession::create([
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'cashier_id' => $user->id,
            'opened_by' => $user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opening_base_amount' => 50,
            'expected_base_amount' => 175,
            'opened_at' => now(),
        ]);
        CashRegisterMovement::create([
            'cash_register_session_id' => $session->id,
            'type' => CashRegisterMovement::TYPE_OPENING,
            'method' => CashRegisterMovement::METHOD_CASH,
            'currency' => Product::CURRENCY_USD,
            'amount' => 50,
            'amount_base' => 50,
            'amount_local' => 0,
            'created_by' => $user->id,
        ]);

        $customer = Customer::create(['name' => 'Cliente Reporte', 'document_type' => 'V', 'document_number' => '123'.$tenant->id]);
        $product = Product::create([
            'name' => 'Producto Reporte',
            'sku' => 'REP-'.$tenant->id,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => $paidTotal,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $sale = Sale::create([
            'customer_id' => $customer->id,
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => $paidTotal,
            'total_local_amount' => $paidTotal,
            'created_by' => $user->id,
            'confirmed_at' => now(),
        ]);
        $saleItem = SaleItem::create([
            'sale_id' => $sale->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'sale_currency' => Product::CURRENCY_USD,
            'unit_price' => $paidTotal,
            'total_amount' => $paidTotal,
            'base_unit_price' => $paidTotal,
            'base_total_amount' => $paidTotal,
        ]);
        $order = PosOrder::create([
            'sale_id' => $sale->id,
            'cash_register_session_id' => $session->id,
            'customer_id' => $customer->id,
            'status' => PosOrder::STATUS_PAID,
            'cashier_id' => $user->id,
            'customer_name' => $customer->name,
            'total_base_amount' => $paidTotal,
            'paid_base_amount' => $paidTotal,
            'opened_at' => now(),
            'paid_at' => now(),
            'closed_at' => now(),
        ]);
        $method = PaymentMethod::create([
            'name' => 'Efectivo USD',
            'code' => 'CASH-'.$tenant->id,
            'method' => PosPayment::METHOD_CASH,
            'currency_mode' => PaymentMethod::CURRENCY_USD,
            'requires_reference' => false,
        ]);
        PosPayment::create([
            'pos_order_id' => $order->id,
            'payment_method_id' => $method->id,
            'method' => PosPayment::METHOD_CASH,
            'currency' => Product::CURRENCY_USD,
            'amount' => $paidTotal,
            'amount_base' => $paidTotal,
            'amount_local' => 0,
            'status' => PosPayment::STATUS_CAPTURED,
            'reference' => 'REF-REPORT',
        ]);

        AccountsReceivable::create([
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'status' => AccountsReceivable::STATUS_PARTIAL,
            'document_number' => 'VENTA-'.$sale->id,
            'original_base_amount' => $paidTotal,
            'collected_base_amount' => $paidTotal - 25,
            'balance_base_amount' => 25,
            'opened_at' => now(),
        ]);

        $salesReturn = SalesReturn::create([
            'sale_id' => $sale->id,
            'status' => SalesReturn::STATUS_REQUESTED,
            'reason' => 'Prueba',
            'created_by' => $user->id,
        ]);
        $salesReturn->items()->create([
            'sale_item_id' => $saleItem->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'condition' => 'sellable',
            'reason' => 'Prueba',
        ]);

        return $sale;
    }

    private function userInTenant(Tenant $tenant, array $permissions): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $user, 'Reportes '.md5(implode('|', $permissions).$tenant->id), $permissions);

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
