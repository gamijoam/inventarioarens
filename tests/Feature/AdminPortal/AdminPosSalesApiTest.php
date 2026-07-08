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

class AdminPosSalesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_sales_list_returns_summary_and_orders(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa POS', 'slug' => 'empresa-pos']);
        $user = $this->userInTenant($tenant, ['sales.view']);
        $paidOrder = $this->seedPosOrder($tenant, $user, 'SKU-A', 80, PosOrder::STATUS_PAID);
        $this->seedPosOrder($tenant, $user, 'SKU-B', 25, PosOrder::STATUS_OPEN);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/pos-sales?period=today')
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'empresa-pos')
            ->assertJsonPath('data.summary.orders_count', 2)
            ->assertJsonPath('data.summary.paid_count', 1)
            ->assertJsonPath('data.summary.open_count', 1)
            ->assertJsonPath('data.summary.total_base_amount', 105)
            ->assertJsonPath('data.filters.options.branches.0.name', 'Principal');

        $orders = collect($response->json('data.data'));

        $this->assertTrue($orders->contains(fn (array $order): bool => $order['id'] === $paidOrder->id && $order['status_label'] === 'Pagada'));
    }

    public function test_pos_sales_can_search_by_product_sku(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa POS', 'slug' => 'empresa-pos']);
        $user = $this->userInTenant($tenant, ['sales.view']);
        $this->seedPosOrder($tenant, $user, 'SKU-SEARCH', 40, PosOrder::STATUS_PAID);
        $this->seedPosOrder($tenant, $user, 'SKU-OTHER', 90, PosOrder::STATUS_PAID);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/pos-sales?period=today&search=SKU-SEARCH')
            ->assertOk()
            ->assertJsonPath('data.summary.orders_count', 1)
            ->assertJsonPath('data.data.0.total_base_amount', 40);
    }

    public function test_pos_sales_detail_returns_items_and_payments(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa POS', 'slug' => 'empresa-pos']);
        $user = $this->userInTenant($tenant, ['sales.view']);
        $order = $this->seedPosOrder($tenant, $user, 'SKU-DET', 55, PosOrder::STATUS_PAID);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/admin-portal/pos-sales/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.items.0.product_sku', 'SKU-DET')
            ->assertJsonPath('data.items.0.base_total_amount', 55)
            ->assertJsonPath('data.payments.0.amount_base', 55)
            ->assertJsonPath('data.payments.0.reference', 'PAY-SKU-DET');
    }

    public function test_pos_sales_do_not_show_other_tenant_orders(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');

        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA, ['sales.view']);
        $userB = $this->userInTenant($tenantB, ['sales.view']);
        $this->seedPosOrder($tenantA, $userA, 'SKU-A', 10, PosOrder::STATUS_PAID);
        $orderB = $this->seedPosOrder($tenantB, $userB, 'SKU-B', 99, PosOrder::STATUS_PAID);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/admin-portal/pos-sales?period=today')
            ->assertOk()
            ->assertJsonPath('data.summary.orders_count', 1)
            ->assertJsonMissing(['total_base_amount' => 99]);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson("/api/admin-portal/pos-sales/{$orderB->id}")
            ->assertNotFound();
    }

    public function test_pos_sales_can_export_csv(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');

        $tenant = Tenant::create(['name' => 'Empresa POS', 'slug' => 'empresa-pos']);
        $user = $this->userInTenant($tenant, ['sales.view']);
        $this->seedPosOrder($tenant, $user, 'SKU-CSV', 70, PosOrder::STATUS_PAID);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get('/api/admin-portal/pos-sales?period=today&export=csv');

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Orden,Cliente,Estado,Sucursal', $csv);
        $this->assertStringContainsString('Cliente SKU-CSV', $csv);
        $this->assertStringContainsString('Pagada', $csv);
    }

    public function test_pos_sales_require_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa POS', 'slug' => 'empresa-pos']);
        $user = $this->userInTenant($tenant, ['products.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/admin-portal/pos-sales')
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

    private function seedPosOrder(Tenant $tenant, User $user, string $sku, float $total, string $status): PosOrder
    {
        $this->useTenant($tenant);

        $branch = Branch::firstOrCreate(['code' => 'BR-1'], ['name' => 'Principal']);
        $warehouse = Warehouse::firstOrCreate(['code' => 'WH-1'], ['branch_id' => $branch->id, 'name' => 'Tienda']);
        $register = CashRegister::firstOrCreate(['code' => 'CJ-1'], ['branch_id' => $branch->id, 'name' => 'Caja Principal']);
        $session = CashRegisterSession::firstOrCreate(
            ['cash_register_id' => $register->id, 'status' => CashRegisterSession::STATUS_OPEN],
            [
                'branch_id' => $branch->id,
                'cashier_id' => $user->id,
                'opened_by' => $user->id,
                'opening_base_amount' => 0,
                'expected_base_amount' => $total,
                'opened_at' => now(),
            ],
        );
        $product = Product::create([
            'name' => 'Producto '.$sku,
            'sku' => $sku,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => $total,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $sale = Sale::create([
            'status' => $status === PosOrder::STATUS_PAID ? Sale::STATUS_CONFIRMED : Sale::STATUS_DRAFT,
            'total_base_amount' => $total,
            'total_local_amount' => $total * 500,
            'created_by' => $user->id,
            'confirmed_at' => $status === PosOrder::STATUS_PAID ? now() : null,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'sale_currency' => Product::CURRENCY_USD,
            'unit_price' => $total,
            'total_amount' => $total,
            'base_unit_price' => $total,
            'base_total_amount' => $total,
        ]);

        $order = PosOrder::create([
            'sale_id' => $sale->id,
            'cash_register_session_id' => $session->id,
            'status' => $status,
            'cashier_id' => $user->id,
            'customer_name' => 'Cliente '.$sku,
            'total_base_amount' => $total,
            'total_local_amount' => $total * 500,
            'paid_base_amount' => $status === PosOrder::STATUS_PAID ? $total : 0,
            'paid_local_amount' => $status === PosOrder::STATUS_PAID ? $total * 500 : 0,
            'opened_at' => now(),
            'paid_at' => $status === PosOrder::STATUS_PAID ? now() : null,
            'closed_at' => $status === PosOrder::STATUS_PAID ? now() : null,
        ]);

        if ($status === PosOrder::STATUS_PAID) {
            PosPayment::create([
                'pos_order_id' => $order->id,
                'method' => PosPayment::METHOD_CASH,
                'currency' => Product::CURRENCY_USD,
                'amount' => $total,
                'amount_base' => $total,
                'amount_local' => $total * 500,
                'status' => PosPayment::STATUS_CAPTURED,
                'reference' => 'PAY-'.$sku,
            ]);
        }

        return $order;
    }

    private function userInTenant(Tenant $tenant, array $permissions): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $user, 'Ventas '.md5(implode('|', $permissions).$tenant->id), $permissions);

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
