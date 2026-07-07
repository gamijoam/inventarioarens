<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAudit;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_serialized_product_inside_current_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Telefonos Demo', 'slug' => 'telefonos-demo']);
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Catalog Manager', ['products.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/products', [
                'name' => 'Samsung A06',
                'sku' => 'SAMSUNG-A06',
                'tracking_type' => Product::TRACKING_SERIALIZED,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Samsung A06')
            ->assertJsonPath('data.sku', 'SAMSUNG-A06')
            ->assertJsonPath('data.tracking_type', Product::TRACKING_SERIALIZED)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('products', [
            'tenant_id' => $tenant->id,
            'sku' => 'SAMSUNG-A06',
            'tracking_type' => Product::TRACKING_SERIALIZED,
        ]);
        $this->assertDatabaseHas('product_audits', [
            'tenant_id' => $tenant->id,
            'action' => ProductAudit::ACTION_CREATED,
            'created_by' => $user->id,
        ]);
    }

    public function test_user_can_create_product_when_product_audits_table_is_missing(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Catalog Manager', ['products.create']);
        Schema::dropIfExists('product_audits');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/products', [
                'name' => 'Producto sin auditoria',
                'sku' => 'SIN-AUDITORIA',
                'tracking_type' => Product::TRACKING_QUANTITY,
                'base_price' => 100,
                'sale_currency' => Product::CURRENCY_USD,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Producto sin auditoria')
            ->assertJsonPath('data.sku', 'SIN-AUDITORIA');

        $this->assertDatabaseHas('products', [
            'tenant_id' => $tenant->id,
            'sku' => 'SIN-AUDITORIA',
        ]);
    }

    public function test_user_can_create_product_with_price_and_exchange_rate_type(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $parallel = $this->rateTypeFor($tenant, 'PARALELO', 'Tasa paralelo');
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Catalog Manager', ['products.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/products', [
                'name' => 'Samsung A06',
                'sku' => 'SAMSUNG-A06',
                'base_price' => 100,
                'sale_currency' => Product::CURRENCY_VES,
                'sale_exchange_rate_type_id' => $parallel->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.base_price', 100)
            ->assertJsonPath('data.sale_currency', Product::CURRENCY_VES)
            ->assertJsonPath('data.sale_exchange_rate_type_id', $parallel->id)
            ->assertJsonPath('data.sale_exchange_rate_type.code', 'PARALELO');

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'product.created',
            'aggregate_type' => 'product',
            'status' => 'pending',
        ]);
    }

    public function test_user_can_manage_product_price_lists_and_quote_selected_price(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $bcv = $this->rateTypeFor($tenant, 'BCV', 'Tasa BCV', true);
        $this->rateFor($tenant, $bcv, 500, true);
        $product = $this->productFor($tenant, 'Samsung A06', 'SAMSUNG-A06', [
            'base_price' => 20,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Catalog Manager', ['products.view', 'products.update']);

        $priceListId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/price-lists', [
                'name' => 'Precio al mayor',
                'code' => 'mayor',
                'is_default' => true,
                'sort_order' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'MAYOR')
            ->assertJsonPath('data.is_default', true)
            ->json('data.id');

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'price_list.created',
            'aggregate_type' => 'price_list',
            'status' => 'pending',
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/products/{$product->id}/prices", [
                'prices' => [[
                    'price_list_id' => $priceListId,
                    'price' => 10,
                    'currency' => Product::CURRENCY_USD,
                    'exchange_rate_type_id' => $bcv->id,
                ]],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.price_list.code', 'MAYOR')
            ->assertJsonPath('data.0.price', 10);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/products/{$product->id}/prices", [
                'prices' => [[
                    'price_list_id' => $priceListId,
                    'price' => 12,
                    'currency' => Product::CURRENCY_USD,
                    'exchange_rate_type_id' => $bcv->id,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.price', 12);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/products/{$product->id}/price-history")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.price_list_name', 'Precio al mayor')
            ->assertJsonPath('data.0.before.price', 10)
            ->assertJsonPath('data.0.after.price', 12)
            ->assertJsonPath('data.1.before', null)
            ->assertJsonPath('data.1.after.price', 10);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/products/{$product->id}/price?price_list_id={$priceListId}")
            ->assertOk()
            ->assertJsonPath('data.price_source', 'price_list')
            ->assertJsonPath('data.price_list_id', $priceListId)
            ->assertJsonPath('data.price_list_name', 'Precio al mayor')
            ->assertJsonPath('data.sale_price', 12)
            ->assertJsonPath('data.price_ves', 6000);

        $this->assertDatabaseHas('product_prices', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'price_list_id' => $priceListId,
            'price' => '12.0000',
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'product_price.created',
            'aggregate_type' => 'product_price',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'product_price.updated',
            'aggregate_type' => 'product_price',
            'status' => 'pending',
        ]);
    }

    public function test_product_price_list_does_not_mix_companies(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];
        $productA = $this->productFor($tenantA, 'Samsung A06', 'SAMSUNG-A06', ['base_price' => 20]);
        $foreignList = $this->priceListFor($tenantB, 'Precio externo', 'EXTERNO');
        $user = $this->userInTenant($tenantA);

        $this->grantRole($tenantA, $user, 'Catalog Manager', ['products.view', 'products.update']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->putJson("/api/products/{$productA->id}/prices", [
                'prices' => [[
                    'price_list_id' => $foreignList->id,
                    'price' => 10,
                    'currency' => Product::CURRENCY_USD,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prices.0.price_list_id']);
    }

    public function test_product_price_endpoint_uses_assigned_active_rate_type(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $bcv = $this->rateTypeFor($tenant, 'BCV', 'Tasa BCV', true);
        $parallel = $this->rateTypeFor($tenant, 'PARALELO', 'Tasa paralelo');
        $this->rateFor($tenant, $bcv, 500, true);
        $this->rateFor($tenant, $parallel, 600, true);
        $product = $this->productFor($tenant, 'Samsung A06', 'SAMSUNG-A06', [
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_VES,
            'sale_exchange_rate_type_id' => $parallel->id,
        ]);
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Vendedor', ['products.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/products/{$product->id}/price")
            ->assertOk()
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.base_price_usd', 100)
            ->assertJsonPath('data.sale_currency', Product::CURRENCY_VES)
            ->assertJsonPath('data.sale_price', 60000)
            ->assertJsonPath('data.price_usd', 100)
            ->assertJsonPath('data.price_ves', 60000)
            ->assertJsonPath('data.exchange_rate_type_code', 'PARALELO')
            ->assertJsonPath('data.exchange_rate', 600);
    }

    public function test_product_price_endpoint_uses_default_rate_type_when_product_has_no_assigned_type(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $bcv = $this->rateTypeFor($tenant, 'BCV', 'Tasa BCV', true);
        $this->rateFor($tenant, $bcv, 500, true);
        $product = $this->productFor($tenant, 'Redmi A3', 'REDMI-A3', [
            'base_price' => 80,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Vendedor', ['products.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/products/{$product->id}/price")
            ->assertOk()
            ->assertJsonPath('data.sale_price', 80)
            ->assertJsonPath('data.price_usd', 80)
            ->assertJsonPath('data.price_ves', 40000)
            ->assertJsonPath('data.exchange_rate_type_code', 'BCV');
    }

    public function test_product_price_endpoint_rejects_selected_list_without_product_price(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $product = $this->productFor($tenant, 'Redmi A3', 'REDMI-A3', [
            'base_price' => 80,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $priceList = $this->priceListFor($tenant, 'Precio mayor', 'MAYOR');
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Vendedor', ['products.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/products/{$product->id}/price?price_list_id={$priceList->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price_list_id'])
            ->assertJsonPath('errors.price_list_id.0', 'Este producto no tiene precio en esta lista.');
    }

    public function test_product_rejects_exchange_rate_type_from_another_tenant(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];
        $foreignType = $this->rateTypeFor($tenantB, 'PARALELO', 'Tasa paralelo B');
        $user = $this->userInTenant($tenantA);

        $this->grantRole($tenantA, $user, 'Catalog Manager', ['products.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/products', [
                'name' => 'Samsung A06',
                'sku' => 'SAMSUNG-A06',
                'base_price' => 100,
                'sale_currency' => Product::CURRENCY_VES,
                'sale_exchange_rate_type_id' => $foreignType->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sale_exchange_rate_type_id']);
    }

    public function test_product_price_requires_active_rate_when_sale_currency_is_ves(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $parallel = $this->rateTypeFor($tenant, 'PARALELO', 'Tasa paralelo');
        $product = $this->productFor($tenant, 'Samsung A06', 'SAMSUNG-A06', [
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_VES,
            'sale_exchange_rate_type_id' => $parallel->id,
        ]);
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Vendedor', ['products.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/products/{$product->id}/price")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exchange_rate']);
    }

    public function test_products_index_does_not_mix_multiple_companies(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        $this->productFor($tenantA, 'Samsung A06', 'SAMSUNG-A06');
        $this->productFor($tenantB, 'Redmi A3', 'REDMI-A3');

        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Vendedor', ['products.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Samsung A06');
    }

    public function test_products_index_can_search_by_name_or_sku_inside_current_tenant(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        $this->productFor($tenantA, 'Samsung A06 Azul', 'SAM-A06-AZUL');
        $this->productFor($tenantA, 'Cable USB', 'CAB-USB');
        $this->productFor($tenantB, 'Samsung Externo', 'SAM-A06-AZUL');

        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Vendedor', ['products.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/products?search=a06&limit=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Samsung A06 Azul')
            ->assertJsonPath('data.0.sku', 'SAM-A06-AZUL');
    }

    public function test_sku_is_unique_inside_tenant_but_can_repeat_between_tenants(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        $this->productFor($tenantB, 'Samsung A06', 'SAME-SKU');
        $this->productFor($tenantA, 'Samsung A06', 'SAME-SKU');

        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Catalog Manager', ['products.create']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/products', [
                'name' => 'Samsung A06 Nuevo',
                'sku' => 'SAME-SKU',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);

        $this->assertDatabaseCount('products', 2);
    }

    public function test_user_can_update_and_deactivate_product_inside_current_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $parallel = $this->rateTypeFor($tenant, 'PARALELO', 'Tasa paralelo');
        $product = $this->productFor($tenant, 'Samsung A06', 'SAMSUNG-A06');
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Catalog Manager', ['products.view', 'products.update', 'products.delete']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/products/{$product->id}", [
                'name' => 'Samsung A06 128GB',
                'tracking_type' => Product::TRACKING_SERIALIZED,
                'base_price' => 125,
                'sale_currency' => Product::CURRENCY_VES,
                'sale_exchange_rate_type_id' => $parallel->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Samsung A06 128GB')
            ->assertJsonPath('data.tracking_type', Product::TRACKING_SERIALIZED)
            ->assertJsonPath('data.base_price', 125)
            ->assertJsonPath('data.sale_currency', Product::CURRENCY_VES)
            ->assertJsonPath('data.sale_exchange_rate_type.code', 'PARALELO');

        $this->assertDatabaseHas('product_audits', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'action' => ProductAudit::ACTION_UPDATED,
            'created_by' => $user->id,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/products/{$product->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'tenant_id' => $tenant->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('product_audits', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'action' => ProductAudit::ACTION_DEACTIVATED,
            'created_by' => $user->id,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/products/{$product->id}", [
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        $this->assertSame(
            3,
            DB::table('sync_outbox')
                ->where('tenant_id', $tenant->id)
                ->where('event_type', 'product.updated')
                ->where('aggregate_type', 'product')
                ->count()
        );
    }

    public function test_product_with_serialized_units_cannot_change_tracking_type(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'WH']);
        $product = Product::create([
            'name' => 'Samsung A06',
            'sku' => 'SAMSUNG-A06',
            'tracking_type' => Product::TRACKING_SERIALIZED,
        ]);
        ProductUnit::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => 'IMEI-A06-001',
        ]);

        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Catalog Manager', ['products.view', 'products.update']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.can_change_tracking_type', false)
            ->assertJsonPath('data.units_count', 1);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/products/{$product->id}", [
                'tracking_type' => Product::TRACKING_QUANTITY,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tracking_type']);
    }

    public function test_user_can_update_sku_without_mixing_tenants(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        $productA = $this->productFor($tenantA, 'Samsung A06', 'SAMSUNG-A06');
        $this->productFor($tenantA, 'Redmi A3', 'REDMI-A3');
        $this->productFor($tenantB, 'Producto externo', 'SKU-EXTERNO');
        $user = $this->userInTenant($tenantA);

        $this->grantRole($tenantA, $user, 'Catalog Manager', ['products.view', 'products.update']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->patchJson("/api/products/{$productA->id}", [
                'sku' => 'REDMI-A3',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->patchJson("/api/products/{$productA->id}", [
                'sku' => 'SKU-EXTERNO',
                'name' => 'Samsung A06 editado',
            ])
            ->assertOk()
            ->assertJsonPath('data.sku', 'SKU-EXTERNO')
            ->assertJsonPath('data.name', 'Samsung A06 editado');

        $this->assertDatabaseHas('products', [
            'id' => $productA->id,
            'tenant_id' => $tenantA->id,
            'sku' => 'SKU-EXTERNO',
        ]);
    }

    public function test_product_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/products', [
                'name' => 'Samsung A06',
                'sku' => 'SAMSUNG-A06',
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

    private function productFor(Tenant $tenant, string $name, string $sku, array $attributes = []): Product
    {
        $this->useTenant($tenant);

        return Product::create(array_merge([
            'name' => $name,
            'sku' => $sku,
        ], $attributes));
    }

    private function rateTypeFor(Tenant $tenant, string $code, string $name, bool $isDefault = false): ExchangeRateType
    {
        $this->useTenant($tenant);

        return ExchangeRateType::create([
            'code' => $code,
            'name' => $name,
            'is_default' => $isDefault,
        ]);
    }

    private function priceListFor(Tenant $tenant, string $name, string $code, bool $isDefault = false): PriceList
    {
        $this->useTenant($tenant);

        return PriceList::create([
            'name' => $name,
            'code' => $code,
            'is_default' => $isDefault,
        ]);
    }

    private function rateFor(Tenant $tenant, ExchangeRateType $type, float $rate, bool $isActive): ExchangeRate
    {
        $this->useTenant($tenant);

        return ExchangeRate::create([
            'exchange_rate_type_id' => $type->id,
            'rate' => $rate,
            'effective_at' => '2026-07-02 12:00:00',
            'is_active' => $isActive,
        ]);
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
