<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CommissionControlApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_control_returns_product_rows_dynamic_payment_columns_and_frozen_ves_equivalent(): void
    {
        [$tenant, $seller] = $this->tenantUser('control-dinamico', 'seller@control.test', 'commissions.view_all');
        $cashier = User::factory()->create(['email' => 'cashier@control.test']);
        $tenant->users()->attach($cashier, ['status' => 'active']);
        app(TenantManager::class)->set($tenant);

        $now = now()->subDay();
        $branchId = DB::table('branches')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'MAIN',
            'name' => 'Principal',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $warehouseId = DB::table('warehouses')->insertGetId([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchId,
            'code' => 'MAIN-01',
            'name' => 'Almacen principal',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $rateTypeId = DB::table('exchange_rate_types')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'BCV',
            'name' => 'BCV',
            'is_default' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $product = Product::create([
            'sku' => 'CONTROL-001',
            'name' => 'Producto control',
            'sale_currency' => 'VES',
            'sale_price' => 5000,
        ]);
        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenant->id,
            'status' => 'confirmed',
            'total_base_amount' => 100,
            'total_local_amount' => 5000,
            'created_by' => $seller->id,
            'confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $saleItemId = DB::table('sale_items')->insertGetId([
            'tenant_id' => $tenant->id,
            'sale_id' => $saleId,
            'warehouse_id' => $warehouseId,
            'product_id' => $product->id,
            'quantity' => 1,
            'sale_currency' => 'VES',
            'unit_price' => 5000,
            'total_amount' => 5000,
            'base_unit_price' => 100,
            'base_total_amount' => 100,
            'exchange_rate_type_id' => $rateTypeId,
            'exchange_rate_type_code' => 'BCV',
            'exchange_rate' => 50,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderId = DB::table('pos_orders')->insertGetId([
            'tenant_id' => $tenant->id,
            'sale_id' => $saleId,
            'seller_id' => $seller->id,
            'cashier_id' => $cashier->id,
            'status' => 'paid',
            'total_base_amount' => 100,
            'total_local_amount' => 5000,
            'paid_base_amount' => 100,
            'paid_local_amount' => 5000,
            'opened_at' => $now,
            'paid_at' => $now,
            'closed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $mobileId = $this->paymentMethod($tenant, 'P.M.', 'Pago Movil', 'mobile_payment');
        $cardId = $this->paymentMethod($tenant, 'P.V.', 'Punto de Venta', 'card');
        DB::table('pos_payments')->insert([
            [
                'tenant_id' => $tenant->id,
                'pos_order_id' => $orderId,
                'payment_method_id' => $mobileId,
                'method' => 'mobile_payment',
                'currency' => 'VES',
                'amount' => 3000,
                'amount_base' => 60,
                'amount_local' => 3000,
                'exchange_rate_type_id' => $rateTypeId,
                'exchange_rate_type_code' => 'BCV',
                'exchange_rate' => 50,
                'status' => 'captured',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'tenant_id' => $tenant->id,
                'pos_order_id' => $orderId,
                'payment_method_id' => $cardId,
                'method' => 'card',
                'currency' => 'USD',
                'amount' => 40,
                'amount_base' => 40,
                'amount_local' => 2000,
                'exchange_rate_type_id' => $rateTypeId,
                'exchange_rate_type_code' => 'BCV',
                'exchange_rate' => 50,
                'status' => 'captured',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        CommissionEntry::create([
            'entry_uuid' => (string) Str::uuid(),
            'sale_id' => $saleId,
            'pos_order_id' => $orderId,
            'sale_item_id' => $saleItemId,
            'beneficiary_user_id' => $seller->id,
            'beneficiary_role' => 'seller',
            'entry_type' => 'earning',
            'plan_name_snapshot' => 'Plan vendedor',
            'percentage_snapshot' => 3,
            'sale_currency' => 'VES',
            'source_amount' => 5000,
            'eligible_base_amount' => 100,
            'exchange_rate_type_id' => $rateTypeId,
            'exchange_rate_type_code' => 'BCV',
            'exchange_rate' => 50,
            'commission_base_amount' => 3,
            'status' => 'available',
            'earned_at' => $now,
            'available_at' => $now,
        ]);

        $response = $this->actingAs($seller)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/commissions/control?date_from='.$now->toDateString().'&date_to='.$now->toDateString())
            ->assertOk();

        $response->assertJsonPath('meta.columns.0.key', 'quantity')
            ->assertJsonPath('meta.payment_columns.0.label', 'P.M.')
            ->assertJsonPath('data.0.product.name', 'Producto control')
            ->assertJsonPath('data.0.quantity', '1.0000')
            ->assertJsonPath('data.0.amount_ves', '5000.0000')
            ->assertJsonPath('data.0.equivalent_usd', '100.0000')
            ->assertJsonPath('data.0.payment_columns.payment_method_'.$mobileId.'.amount', '3000.0000')
            ->assertJsonPath('data.0.commission_ves', '150.0000');
    }

    public function test_control_returns_equivalent_for_usd_sale_paid_in_ves(): void
    {
        [$tenant, $seller] = $this->tenantUser('control-usd-ves', 'usd-ves@control.test', 'commissions.view_all');
        app(TenantManager::class)->set($tenant);

        $now = now()->subDay();
        $branchId = DB::table('branches')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'MAIN',
            'name' => 'Principal',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $warehouseId = DB::table('warehouses')->insertGetId([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchId,
            'code' => 'MAIN-01',
            'name' => 'Almacen principal',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $product = Product::create([
            'sku' => 'CONTROL-USD-VES',
            'name' => 'Producto USD cobrado en VES',
            'sale_currency' => 'USD',
            'sale_price' => 7.33,
        ]);
        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenant->id,
            'status' => 'confirmed',
            'total_base_amount' => 7.33,
            'total_local_amount' => 21990,
            'created_by' => $seller->id,
            'confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $saleItemId = DB::table('sale_items')->insertGetId([
            'tenant_id' => $tenant->id,
            'sale_id' => $saleId,
            'warehouse_id' => $warehouseId,
            'product_id' => $product->id,
            'quantity' => 1,
            'sale_currency' => 'USD',
            'unit_price' => 7.33,
            'total_amount' => 7.33,
            'base_unit_price' => 7.33,
            'base_total_amount' => 7.33,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderId = DB::table('pos_orders')->insertGetId([
            'tenant_id' => $tenant->id,
            'sale_id' => $saleId,
            'seller_id' => $seller->id,
            'status' => 'paid',
            'total_base_amount' => 7.33,
            'total_local_amount' => 21990,
            'paid_base_amount' => 7.33,
            'paid_local_amount' => 21990,
            'opened_at' => $now,
            'paid_at' => $now,
            'closed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $paymentMethodId = $this->paymentMethod($tenant, 'TRANSF-VES', 'Transferencia VES', 'transfer');
        DB::table('pos_payments')->insert([
            'tenant_id' => $tenant->id,
            'pos_order_id' => $orderId,
            'payment_method_id' => $paymentMethodId,
            'method' => 'transfer',
            'currency' => 'VES',
            'amount' => 21990,
            'amount_base' => 7.33,
            'amount_local' => 21990,
            'exchange_rate_type_code' => 'BCV',
            'exchange_rate' => 3000,
            'status' => 'captured',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->actingAs($seller)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/commissions/control?date_from='.$now->toDateString().'&date_to='.$now->toDateString())
            ->assertOk();

        $response->assertJsonPath('data.0.amount_usd', '7.3300')
            ->assertJsonPath('data.0.equivalent_usd', '7.3300')
            ->assertJsonPath('data.0.exchange_rate_type_code', 'BCV')
            ->assertJsonPath('data.0.exchange_rate', '3000.000000')
            ->assertJsonPath('data.0.payment_columns.payment_method_'.$paymentMethodId.'.amount', '21990.0000');
    }

    private function tenantUser(string $slug, string $email, string $permission): array
    {
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug, 'status' => 'active']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $user = User::factory()->create(['email' => $email]);
        $tenant->users()->attach($user, ['status' => 'active']);
        $role = Role::findOrCreate('Control '.$slug, 'web');
        $role->syncPermissions([$permission]);
        $user->assignRole($role);

        return [$tenant, $user];
    }

    private function paymentMethod(Tenant $tenant, string $code, string $name, string $method): int
    {
        return (int) DB::table('payment_methods')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => $code,
            'report_code' => $code,
            'report_label' => $name,
            'name' => $name,
            'method' => $method,
            'currency_mode' => 'flexible',
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
