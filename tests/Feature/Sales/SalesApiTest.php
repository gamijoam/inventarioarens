<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
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

class SalesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_draft_sale_copying_product_price_and_rate_snapshot(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, 'PARALELO', 600);
        $customer = $this->customer($tenant, 'Cliente Venta', Customer::DOCUMENT_V, '123');
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Vendedor', ['sales.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales', [
                'customer_id' => $customer->id,
                'items' => [
                    [
                        'warehouse_id' => $warehouse->id,
                        'product_id' => $product->id,
                        'quantity' => 2,
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', Sale::STATUS_DRAFT)
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.customer.name', 'Cliente Venta')
            ->assertJsonPath('data.total_base_amount', 200)
            ->assertJsonPath('data.total_local_amount', 120000)
            ->assertJsonPath('data.items.0.sale_currency', Product::CURRENCY_VES)
            ->assertJsonPath('data.items.0.unit_price', 60000)
            ->assertJsonPath('data.items.0.exchange_rate_type_code', 'PARALELO')
            ->assertJsonPath('data.items.0.exchange_rate', 600);
    }

    public function test_confirm_sale_decreases_inventory_and_links_stock_movement(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 5,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor', ['sales.create']);

        $saleId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales', [
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/sales/{$saleId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', Sale::STATUS_CONFIRMED);

        $this->assertNotNull($response->json('data.items.0.stock_movement_id'));

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '3.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'sale',
            'reference_type' => Sale::class,
            'reference_id' => $saleId,
        ]);
    }

    public function test_confirm_sale_rejects_insufficient_stock(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, 'BCV', 500);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 1,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor', ['sales.create']);

        $saleId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales', [
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/sales/{$saleId}/confirm")
            ->assertUnprocessable();
    }

    public function test_confirm_serialized_sale_stores_and_sells_exact_imei(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, 'BCV', 500, Product::TRACKING_SERIALIZED);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 1,
        ]);
        $unit = ProductUnit::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860001222222222',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor', ['sales.create']);

        $saleId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales', [
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'product_unit_ids' => [$unit->id],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.product_unit_ids.0', $unit->id)
            ->assertJsonPath('data.items.0.serial_units.0.serial_number', '860001222222222')
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/sales/{$saleId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', Sale::STATUS_CONFIRMED)
            ->assertJsonPath('data.items.0.product_unit_ids.0', $unit->id)
            ->assertJsonPath('data.items.0.serial_units.0.status', ProductUnit::STATUS_SOLD);

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $unit->id,
            'status' => ProductUnit::STATUS_SOLD,
        ]);
    }

    public function test_serialized_sale_requires_one_available_imei_per_unit(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, 'BCV', 500, Product::TRACKING_SERIALIZED);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 1,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor', ['sales.create']);

        $saleId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales', [
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/sales/{$saleId}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_unit_ids']);
    }

    public function test_user_can_cancel_draft_sale_without_moving_inventory(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, 'BCV', 500);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor', ['sales.create', 'sales.cancel']);

        $saleId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales', [
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/sales/{$saleId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', Sale::STATUS_CANCELLED);

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_sales_do_not_mix_companies_and_reject_foreign_resources(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];
        [$warehouseA, $productA] = $this->pricedProduct($tenantA, 'BCV', 500);
        [, $productB] = $this->pricedProduct($tenantB, 'BCV', 700);
        $customerB = $this->customer($tenantB, 'Cliente B', Customer::DOCUMENT_V, '222');
        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Vendedor', ['sales.view', 'sales.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/sales', [
                'customer_id' => $customerB->id,
                'items' => [[
                    'warehouse_id' => $warehouseA->id,
                    'product_id' => $productA->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/sales', [
                'items' => [[
                    'warehouse_id' => $warehouseA->id,
                    'product_id' => $productA->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/sales')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/sales', [
                'items' => [[
                    'warehouse_id' => $warehouseA->id,
                    'product_id' => $productB->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_id']);
    }

    public function test_sales_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->pricedProduct($tenant, 'BCV', 500);
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales', [
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
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

    private function pricedProduct(Tenant $tenant, string $rateCode, float $rate, string $trackingType = Product::TRACKING_QUANTITY): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => "BR-{$rateCode}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => "WH-{$rateCode}"]);
        $rateType = ExchangeRateType::create(['code' => $rateCode, 'name' => "Tasa {$rateCode}", 'is_default' => true]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'rate' => $rate,
            'effective_at' => '2026-07-02 12:00:00',
            'is_active' => true,
        ]);
        $product = Product::create([
            'name' => "Producto {$rateCode}",
            'sku' => "SKU-{$rateCode}",
            'tracking_type' => $trackingType,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_VES,
            'sale_exchange_rate_type_id' => $rateType->id,
        ]);

        return [$warehouse, $product];
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
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
