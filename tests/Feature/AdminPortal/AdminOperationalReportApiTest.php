<?php

namespace Tests\Feature\AdminPortal;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
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
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminOperationalReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_reports_return_sales_cash_and_product_metrics(): void
    {
        Carbon::setTestNow('2026-07-07 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant, ['reports.view']);
        $this->seedOperationalData($tenant, $user, 120.5);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/operational-reports?period=today')
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'empresa-a')
            ->assertJsonPath('data.period.from', '2026-07-07')
            ->assertJsonPath('data.sales.confirmed_count', 1)
            ->assertJsonPath('data.sales.confirmed_base_amount', 120.5)
            ->assertJsonPath('data.sales.pos_paid_count', 1)
            ->assertJsonPath('data.sales.pos_paid_base_amount', 120.5)
            ->assertJsonPath('data.sales.average_ticket_base_amount', 120.5)
            ->assertJsonPath('data.sales.pending_pos_count', 1)
            ->assertJsonPath('data.sales.pending_pos_base_amount', 35)
            ->assertJsonPath('data.cash_register.open_count', 1)
            ->assertJsonPath('data.cash_register.expected_base_amount', 30)
            ->assertJsonPath('data.cash_register.sessions.0.cash_register_name', 'Caja Principal')
            ->assertJsonPath('data.payment_methods.0.name', 'Efectivo USD')
            ->assertJsonPath('data.payment_methods.0.payments_count', 1)
            ->assertJsonPath('data.payment_methods.0.amount_base', 120.5)
            ->assertJsonPath('data.top_products.0.product_sku', 'TOP-A')
            ->assertJsonPath('data.top_products.0.quantity', 2)
            ->assertJsonFragment(['customer_name' => 'Cliente Reporte']);
    }

    public function test_operational_reports_do_not_mix_tenant_data(): void
    {
        Carbon::setTestNow('2026-07-07 10:00:00');

        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA, ['reports.view']);
        $userB = $this->userInTenant($tenantB, ['reports.view']);

        $this->seedOperationalData($tenantA, $userA, 120.5, 'A');
        $this->seedOperationalData($tenantB, $userB, 999.99, 'B');

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/admin-portal/operational-reports?period=today')
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'empresa-a')
            ->assertJsonPath('data.sales.pos_paid_base_amount', 120.5)
            ->assertJsonPath('data.top_products.0.product_sku', 'TOP-A');
    }

    public function test_operational_reports_require_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant, ['products.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/operational-reports')
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

    private function seedOperationalData(Tenant $tenant, User $user, float $paidTotal, string $suffix = 'A'): void
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal '.$suffix, 'code' => 'BR-'.$suffix]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Tienda '.$suffix, 'code' => 'WH-'.$suffix]);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja Principal', 'code' => 'CJ-'.$suffix]);
        $session = CashRegisterSession::create([
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'cashier_id' => $user->id,
            'opened_by' => $user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opening_base_amount' => 10,
            'expected_base_amount' => 30,
            'opened_at' => now(),
        ]);
        $product = Product::create([
            'name' => 'Producto Top '.$suffix,
            'sku' => 'TOP-'.$suffix,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => $paidTotal / 2,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $sale = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => $paidTotal,
            'total_local_amount' => $paidTotal,
            'created_by' => $user->id,
            'confirmed_at' => now(),
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'sale_currency' => Product::CURRENCY_USD,
            'unit_price' => $paidTotal / 2,
            'total_amount' => $paidTotal,
            'base_unit_price' => $paidTotal / 2,
            'base_total_amount' => $paidTotal,
        ]);
        $paidOrder = PosOrder::create([
            'sale_id' => $sale->id,
            'cash_register_session_id' => $session->id,
            'status' => PosOrder::STATUS_PAID,
            'cashier_id' => $user->id,
            'customer_name' => 'Cliente Reporte',
            'total_base_amount' => $paidTotal,
            'total_local_amount' => $paidTotal,
            'paid_base_amount' => $paidTotal,
            'paid_local_amount' => $paidTotal,
            'opened_at' => now(),
            'paid_at' => now(),
            'closed_at' => now(),
        ]);
        PosPayment::create([
            'pos_order_id' => $paidOrder->id,
            'method' => PosPayment::METHOD_CASH,
            'currency' => Product::CURRENCY_USD,
            'amount' => $paidTotal,
            'amount_base' => $paidTotal,
            'amount_local' => $paidTotal,
            'status' => PosPayment::STATUS_CAPTURED,
            'reference' => 'REP-'.$suffix,
        ]);

        $pendingSale = Sale::create([
            'status' => Sale::STATUS_DRAFT,
            'total_base_amount' => 35,
            'total_local_amount' => 35,
            'created_by' => $user->id,
        ]);
        PosOrder::create([
            'sale_id' => $pendingSale->id,
            'cash_register_session_id' => $session->id,
            'status' => PosOrder::STATUS_OPEN,
            'cashier_id' => $user->id,
            'customer_name' => 'Cliente Pendiente',
            'total_base_amount' => 35,
            'paid_base_amount' => 0,
            'opened_at' => now(),
        ]);
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
