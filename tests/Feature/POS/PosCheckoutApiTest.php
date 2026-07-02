<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
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

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
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

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
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

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
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
    }

    public function test_pos_orders_do_not_mix_companies_and_reject_foreign_resources(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];
        [$warehouseA, $productA] = $this->pricedProduct($tenantA, Product::CURRENCY_USD, 'BCV', 500);
        [, $productB] = $this->pricedProduct($tenantB, Product::CURRENCY_USD, 'BCV', 700);
        $this->useTenant($tenantA);
        StockBalance::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $productA->id,
            'quantity_available' => 2,
        ]);
        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Cajero', ['pos.checkout', 'pos.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/pos/checkouts', [
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

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
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

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function pricedProduct(Tenant $tenant, string $saleCurrency, string $rateCode, float $rate): array
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
