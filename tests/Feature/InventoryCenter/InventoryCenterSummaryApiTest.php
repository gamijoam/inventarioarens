<?php

namespace Tests\Feature\InventoryCenter;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAudit;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warranties\Models\WarrantyPolicy;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryCenterSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_center_returns_real_metrics_and_aggregated_products(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->seedInventory($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/summary?low_stock_threshold=3')
            ->assertOk()
            ->assertJsonPath('data.metrics.total_products', 3)
            ->assertJsonPath('data.metrics.serialized_products', 1)
            ->assertJsonPath('data.metrics.quantity_products', 2)
            ->assertJsonPath('data.metrics.available_quantity', 17)
            ->assertJsonPath('data.metrics.reserved_quantity', 2)
            ->assertJsonPath('data.metrics.damaged_quantity', 1)
            ->assertJsonPath('data.metrics.low_stock_count', 1)
            ->assertJsonPath('data.metrics.without_stock_count', 1)
            ->assertJsonPath('data.pagination.page', 1)
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.last_page', 1)
            ->assertJsonPath('data.products.0.name', 'Audifonos Tipo C')
            ->assertJsonPath('data.products.0.stock.available', 12)
            ->assertJsonPath('data.products.0.stock.status', 'available')
            ->assertJsonPath('data.products.1.name', 'Samsung A06')
            ->assertJsonPath('data.products.1.stock.available', 5)
            ->assertJsonPath('data.products.2.name', 'Xiaomi Serial')
            ->assertJsonPath('data.products.2.stock.status', 'out');
    }

    public function test_inventory_center_filters_by_search_and_stock_status(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->seedInventory($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/summary?search=IMEI&tracking_type=serialized&stock_status=out')
            ->assertOk()
            ->assertJsonPath('data.products.0.name', 'Xiaomi Serial')
            ->assertJsonCount(1, 'data.products');
    }

    public function test_inventory_center_search_is_case_insensitive_for_pos(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->seedInventory($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/summary?search=samsung&stock_status=all')
            ->assertOk()
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.name', 'Samsung A06');
    }

    public function test_inventory_center_can_include_inactive_products_for_admin_catalog_management(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->seedInventory($tenant);

        $this->useTenant($tenant);
        Product::where('sku', 'AUD-0')->update(['is_active' => false]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/summary?stock_status=all')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/summary?active_status=inactive&stock_status=all')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.products.0.name', 'Audifonos Tipo C')
            ->assertJsonPath('data.products.0.is_active', false);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/summary?active_status=all&stock_status=all')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 3);
    }

    public function test_inventory_center_exports_filtered_inventory_as_csv(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->seedInventory($tenant);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->get('/api/inventory-center/export?tracking_type=serialized&stock_status=out');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->getContent();

        $this->assertStringContainsString('Producto;SKU;"Tipo de control";Moneda;"Precio base";Disponible;Reservado;Dañado;"Estado de stock"', $content);
        $this->assertStringContainsString('"Xiaomi Serial";IMEI-0;"Serializado / IMEI";USD;90;0;0;0;"Sin stock"', $content);
        $this->assertStringNotContainsString('Samsung A06', $content);
    }

    public function test_inventory_center_bulk_action_assigns_warranty_and_rate_with_audits(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant, ['products.view', 'inventory.view', 'products.update']);
        $this->useTenant($tenant);

        $warranty = WarrantyPolicy::create([
            'name' => 'Android 30 dias',
            'duration_days' => 30,
            'coverage_type' => WarrantyPolicy::COVERAGE_STORE,
            'is_active' => true,
        ]);
        $rate = ExchangeRateType::create([
            'code' => 'BCV',
            'name' => 'Banco Central',
            'is_default' => true,
            'is_active' => true,
        ]);
        $productA = Product::create([
            'name' => 'Samsung A06',
            'sku' => 'BULK-A06',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $productB = Product::create([
            'name' => 'Cable USB',
            'sku' => 'BULK-USB',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 5,
            'sale_currency' => Product::CURRENCY_USD,
            'is_active' => false,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-center/products/bulk-action', [
                'product_ids' => [$productA->id, $productB->id],
                'action' => 'assign_warranty_policy',
                'payload' => ['warranty_policy_id' => $warranty->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.skipped_count', 0);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-center/products/bulk-action', [
                'product_ids' => [$productA->id, $productB->id],
                'action' => 'assign_exchange_rate_type',
                'payload' => ['sale_exchange_rate_type_id' => $rate->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-center/products/bulk-action', [
                'product_ids' => [$productB->id],
                'action' => 'activate',
            ])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1);

        $this->assertDatabaseHas('products', [
            'id' => $productA->id,
            'warranty_policy_id' => $warranty->id,
            'sale_exchange_rate_type_id' => $rate->id,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $productB->id,
            'warranty_policy_id' => $warranty->id,
            'sale_exchange_rate_type_id' => $rate->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('product_audits', 5);
        $this->assertDatabaseHas('product_audits', [
            'product_id' => $productB->id,
            'action' => ProductAudit::ACTION_UPDATED,
            'created_by' => $user->id,
        ]);
    }

    public function test_inventory_center_bulk_action_fills_missing_price_list_without_overwriting_existing_prices(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant, ['products.view', 'inventory.view', 'products.update']);
        $this->useTenant($tenant);

        $priceList = PriceList::create([
            'name' => 'Mayor',
            'code' => 'MAYOR',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $productA = Product::create([
            'name' => 'Samsung A06',
            'sku' => 'PRICE-BULK-A',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $productB = Product::create([
            'name' => 'Cable USB',
            'sku' => 'PRICE-BULK-B',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        ProductPrice::create([
            'product_id' => $productB->id,
            'price_list_id' => $priceList->id,
            'price' => 11,
            'currency' => Product::CURRENCY_USD,
            'is_active' => true,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-center/products/bulk-action', [
                'product_ids' => [$productA->id, $productB->id],
                'action' => 'fill_missing_price_list',
                'payload' => [
                    'price_list_id' => $priceList->id,
                    'strategy' => 'percent_over_base',
                    'percent' => 20,
                    'currency' => Product::CURRENCY_USD,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.skipped.0.reason', 'El producto ya tiene precio para esa lista.');

        $this->assertDatabaseHas('product_prices', [
            'product_id' => $productA->id,
            'price_list_id' => $priceList->id,
            'price' => 120,
            'currency' => Product::CURRENCY_USD,
        ]);
        $this->assertDatabaseHas('product_prices', [
            'product_id' => $productB->id,
            'price_list_id' => $priceList->id,
            'price' => 11,
        ]);
    }

    public function test_inventory_center_bulk_action_updates_price_list_prices_and_creates_missing_prices(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant, ['products.view', 'inventory.view', 'products.update']);
        $this->useTenant($tenant);

        $priceList = PriceList::create([
            'name' => 'Detal',
            'code' => 'DETAL',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $productA = Product::create([
            'name' => 'Samsung A06',
            'sku' => 'PRICE-UPD-A',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        $productB = Product::create([
            'name' => 'Cable USB',
            'sku' => 'PRICE-UPD-B',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        ProductPrice::create([
            'product_id' => $productA->id,
            'price_list_id' => $priceList->id,
            'price' => 80,
            'currency' => Product::CURRENCY_USD,
            'is_active' => true,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/inventory-center/products/bulk-action', [
                'product_ids' => [$productA->id, $productB->id],
                'action' => 'update_price_list',
                'payload' => [
                    'price_list_id' => $priceList->id,
                    'strategy' => 'fixed_price',
                    'price' => 25,
                    'currency' => Product::CURRENCY_USD,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.skipped_count', 0);

        $this->assertDatabaseHas('product_prices', [
            'product_id' => $productA->id,
            'price_list_id' => $priceList->id,
            'price' => 25,
            'currency' => Product::CURRENCY_USD,
        ]);
        $this->assertDatabaseHas('product_prices', [
            'product_id' => $productB->id,
            'price_list_id' => $priceList->id,
            'price' => 25,
            'currency' => Product::CURRENCY_USD,
        ]);
    }

    public function test_inventory_center_bulk_action_rejects_foreign_products_and_requires_update_permission(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $viewer = $this->inventoryUser($tenantA);
        $editor = $this->inventoryUser($tenantA, ['products.view', 'inventory.view', 'products.update']);

        $this->useTenant($tenantA);
        $ownProduct = Product::create([
            'name' => 'Producto A',
            'sku' => 'OWN-BULK',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        $this->useTenant($tenantB);
        $foreignProduct = Product::create([
            'name' => 'Producto B',
            'sku' => 'FOREIGN-BULK',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        $this
            ->actingAs($viewer)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/inventory-center/products/bulk-action', [
                'product_ids' => [$ownProduct->id],
                'action' => 'deactivate',
            ])
            ->assertForbidden();

        $this
            ->actingAs($editor)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/inventory-center/products/bulk-action', [
                'product_ids' => [$foreignProduct->id],
                'action' => 'deactivate',
            ])
            ->assertJsonValidationErrors(['product_ids.0']);
    }

    public function test_inventory_center_paginates_and_filters_by_tracking_type(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->seedInventory($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/summary?limit=1&page=2&tracking_type=quantity')
            ->assertOk()
            ->assertJsonPath('data.filters.tracking_type', Product::TRACKING_QUANTITY)
            ->assertJsonPath('data.pagination.page', 2)
            ->assertJsonPath('data.pagination.limit', 1)
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.pagination.has_previous', true)
            ->assertJsonPath('data.pagination.has_next', false)
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.name', 'Samsung A06');
    }

    public function test_inventory_center_shows_new_active_product_without_stock(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->useTenant($tenant);

        Product::create([
            'name' => 'Producto Recien Creado',
            'sku' => 'NUEVO-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
            'is_active' => true,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/summary?search=NUEVO-001&stock_status=all')
            ->assertOk()
            ->assertJsonPath('data.metrics.total_products', 1)
            ->assertJsonPath('data.metrics.without_stock_count', 1)
            ->assertJsonPath('data.products.0.name', 'Producto Recien Creado')
            ->assertJsonPath('data.products.0.sku', 'NUEVO-001')
            ->assertJsonPath('data.products.0.stock.available', 0)
            ->assertJsonPath('data.products.0.stock.status', 'out');
    }

    public function test_inventory_center_returns_operational_alerts(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Tienda', 'code' => 'STORE']);
        $priceList = PriceList::create([
            'name' => 'Detal',
            'code' => 'DETAL',
            'is_active' => true,
            'is_default' => true,
        ]);

        $complete = Product::create([
            'name' => 'Producto Completo',
            'sku' => 'OK-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        ProductPrice::create([
            'product_id' => $complete->id,
            'price_list_id' => $priceList->id,
            'price' => 12,
            'currency' => Product::CURRENCY_USD,
            'is_active' => true,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $complete->id,
            'quantity_available' => 6,
        ]);

        $lowStock = Product::create([
            'name' => 'Producto Bajo',
            'sku' => 'LOW-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 20,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $lowStock->id,
            'quantity_available' => 2,
        ]);

        Product::create([
            'name' => 'Producto Sin Precio',
            'sku' => 'NO-PRICE',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => null,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/summary?low_stock_threshold=3')
            ->assertOk()
            ->assertJsonPath('data.alerts.0.type', 'low_stock')
            ->assertJsonPath('data.alerts.0.count', 1)
            ->assertJsonPath('data.alerts.1.type', 'without_stock')
            ->assertJsonPath('data.alerts.1.count', 1)
            ->assertJsonPath('data.alerts.2.type', 'without_base_price')
            ->assertJsonPath('data.alerts.2.product_names.0', 'Producto Sin Precio')
            ->assertJsonPath('data.alerts.3.type', 'without_warranty_policy')
            ->assertJsonPath('data.alerts.3.count', 3)
            ->assertJsonPath('data.alerts.4.type', 'missing_price_lists')
            ->assertJsonPath('data.alerts.4.count', 2);
    }

    public function test_inventory_center_product_detail_returns_stock_serials_and_recent_movements(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouseA = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Tienda', 'code' => 'STORE']);
        $warehouseB = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Deposito', 'code' => 'DEPOT']);
        $product = Product::create([
            'name' => 'Samsung A06',
            'sku' => 'A06-DET',
            'tracking_type' => Product::TRACKING_SERIALIZED,
            'base_price' => 120,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        StockBalance::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $product->id,
            'quantity_available' => 2,
            'quantity_reserved' => 1,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity_available' => 1,
            'quantity_damaged' => 1,
        ]);

        ProductUnit::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouseA->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860001000000001',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        ProductUnit::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouseB->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860001000000002',
            'status' => ProductUnit::STATUS_DAMAGED,
        ]);

        StockMovement::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 3,
            'reason' => 'Entrada detalle',
            'created_by' => $user->id,
        ]);
        ProductAudit::create([
            'product_id' => $product->id,
            'action' => ProductAudit::ACTION_UPDATED,
            'changes' => [
                'before' => ['base_price' => 100],
                'after' => ['base_price' => 120],
            ],
            'created_by' => $user->id,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.product.name', 'Samsung A06')
            ->assertJsonPath('data.stock.totals.available', 3)
            ->assertJsonPath('data.stock.totals.reserved', 1)
            ->assertJsonPath('data.stock.totals.damaged', 1)
            ->assertJsonPath('data.stock.by_warehouse.0.warehouse_name', 'Tienda')
            ->assertJsonPath('data.stock.by_warehouse.1.warehouse_name', 'Deposito')
            ->assertJsonPath('data.serials.total', 2)
            ->assertJsonPath('data.serials.items.0.serial_number', '860001000000001')
            ->assertJsonPath('data.recent_movements.0.reason', 'Entrada detalle')
            ->assertJsonPath('data.recent_movements.0.created_by_name', $user->name)
            ->assertJsonPath('data.recent_audits.0.action', ProductAudit::ACTION_UPDATED)
            ->assertJsonPath('data.recent_audits.0.created_by_name', $user->name)
            ->assertJsonPath('data.recent_audits.0.changes.after.base_price', 120);
    }

    public function test_inventory_center_product_detail_works_without_product_audits_table(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->useTenant($tenant);

        $product = Product::create([
            'name' => 'Producto sin auditoria',
            'sku' => 'NO-AUDIT-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        Schema::dropIfExists('product_audits');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.product.name', 'Producto sin auditoria')
            ->assertJsonPath('data.recent_audits', []);
    }

    public function test_inventory_center_product_serials_endpoint_filters_and_paginates(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Tienda', 'code' => 'STORE']);
        $product = Product::create([
            'name' => 'Samsung Serial',
            'sku' => 'SER-PAGE',
            'tracking_type' => Product::TRACKING_SERIALIZED,
            'base_price' => 120,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        ProductUnit::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => 'IMEI-0001',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        ProductUnit::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => 'IMEI-0002',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        ProductUnit::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => 'IMEI-DAÑADO',
            'status' => ProductUnit::STATUS_DAMAGED,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/products/{$product->id}/serials?status=available&limit=1&page=2")
            ->assertOk()
            ->assertJsonPath('data.filters.status', ProductUnit::STATUS_AVAILABLE)
            ->assertJsonPath('data.pagination.page', 2)
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.has_previous', true)
            ->assertJsonPath('data.pagination.has_next', false)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.serial_number', 'IMEI-0002');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/products/{$product->id}/serials?search=DAÑADO")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.status', ProductUnit::STATUS_DAMAGED);
    }

    public function test_inventory_center_product_movements_endpoint_filters_and_paginates(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Tienda', 'code' => 'STORE']);
        $product = Product::create([
            'name' => 'Cable USB',
            'sku' => 'MOV-PAGE',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 5,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 10,
            'reason' => 'Entrada inicial',
            'created_by' => $user->id,
        ]);
        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'adjustment_out',
            'quantity' => 2,
            'reason' => 'Ajuste por conteo',
            'created_by' => $user->id,
        ]);
        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'sale',
            'quantity' => 1,
            'reason' => 'Venta mostrador',
            'created_by' => $user->id,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/products/{$product->id}/movements?type=adjustment_out")
            ->assertOk()
            ->assertJsonPath('data.filters.type', 'adjustment_out')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.reason', 'Ajuste por conteo')
            ->assertJsonPath('data.data.0.created_by_name', $user->name);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/products/{$product->id}/movements?limit=2&page=1")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.has_next', true)
            ->assertJsonCount(2, 'data.data');
    }

    public function test_inventory_center_product_stock_by_warehouse_endpoint_returns_only_current_company(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->inventoryUser($tenantA);
        $this->useTenant($tenantA);

        $branchA = Branch::create(['name' => 'Principal A', 'code' => 'A']);
        $warehouseA = Warehouse::create(['branch_id' => $branchA->id, 'name' => 'Tienda A', 'code' => 'A-STORE']);
        $productA = Product::create([
            'name' => 'Producto A',
            'sku' => 'STOCK-A',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $productA->id,
            'quantity_available' => 7,
            'quantity_reserved' => 1,
            'quantity_damaged' => 0,
        ]);

        $this->useTenant($tenantB);
        $branchB = Branch::create(['name' => 'Principal B', 'code' => 'B']);
        $warehouseB = Warehouse::create(['branch_id' => $branchB->id, 'name' => 'Tienda B', 'code' => 'B-STORE']);
        $productB = Product::create([
            'name' => 'Producto B',
            'sku' => 'STOCK-B',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $productB->id,
            'quantity_available' => 99,
        ]);

        $this->useTenant($tenantA);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson("/api/inventory-center/products/{$productA->id}/stock-by-warehouse")
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.warehouse_name', 'Tienda A')
            ->assertJsonPath('data.data.0.available', 7)
            ->assertJsonMissing(['warehouse_name' => 'Tienda B']);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson("/api/inventory-center/products/{$productB->id}/stock-by-warehouse")
            ->assertForbidden();
    }

    public function test_inventory_center_product_audits_endpoint_filters_and_paginates(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->useTenant($tenant);

        $product = Product::create([
            'name' => 'Producto auditado',
            'sku' => 'AUDIT-PAGE',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        ProductAudit::create([
            'product_id' => $product->id,
            'action' => ProductAudit::ACTION_CREATED,
            'changes' => ['after' => ['name' => 'Producto auditado']],
            'created_by' => $user->id,
        ]);
        ProductAudit::create([
            'product_id' => $product->id,
            'action' => ProductAudit::ACTION_UPDATED,
            'changes' => ['before' => ['base_price' => 10], 'after' => ['base_price' => 12]],
            'created_by' => $user->id,
        ]);
        ProductAudit::create([
            'product_id' => $product->id,
            'action' => ProductAudit::ACTION_DEACTIVATED,
            'changes' => ['before' => ['is_active' => true], 'after' => ['is_active' => false]],
            'created_by' => $user->id,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/products/{$product->id}/audits?action=updated")
            ->assertOk()
            ->assertJsonPath('data.filters.action', ProductAudit::ACTION_UPDATED)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.action', ProductAudit::ACTION_UPDATED)
            ->assertJsonPath('data.data.0.created_by_name', $user->name)
            ->assertJsonPath('data.data.0.created_by_email', $user->email)
            ->assertJsonPath('data.data.0.changes.after.base_price', 12);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/products/{$product->id}/audits?search={$user->email}&limit=2&page=1")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.has_next', true)
            ->assertJsonCount(2, 'data.data');
    }

    public function test_inventory_center_product_audits_endpoint_works_without_product_audits_table(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->inventoryUser($tenant);
        $this->useTenant($tenant);

        $product = Product::create([
            'name' => 'Producto sin tabla auditoria',
            'sku' => 'NO-AUDIT-PAGE',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 10,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        Schema::dropIfExists('product_audits');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/products/{$product->id}/audits")
            ->assertOk()
            ->assertJsonPath('data.data', [])
            ->assertJsonPath('data.pagination.total', 0);
    }

    public function test_inventory_center_does_not_mix_companies(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->inventoryUser($tenantA);

        $this->seedInventory($tenantA);
        $this->seedInventory($tenantB, 1000);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/inventory-center/summary')
            ->assertOk()
            ->assertJsonPath('data.metrics.available_quantity', 17)
            ->assertJsonMissing(['sku' => 'A06-1000']);
    }

    public function test_inventory_center_requires_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/inventory-center/summary')
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

    private function seedInventory(Tenant $tenant, int $offset = 0): void
    {
        $this->useTenant($tenant);

        $branch = Branch::create([
            'name' => "Principal {$offset}",
            'code' => "BR-{$offset}",
        ]);

        $warehouseA = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => "Almacen A {$offset}",
            'code' => "WH-A-{$offset}",
        ]);

        $warehouseB = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => "Almacen B {$offset}",
            'code' => "WH-B-{$offset}",
        ]);

        $samsung = Product::create([
            'name' => 'Samsung A06',
            'sku' => "A06-{$offset}",
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 120,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        $audifonos = Product::create([
            'name' => 'Audifonos Tipo C',
            'sku' => "AUD-{$offset}",
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 8,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        Product::create([
            'name' => 'Xiaomi Serial',
            'sku' => "IMEI-{$offset}",
            'tracking_type' => Product::TRACKING_SERIALIZED,
            'base_price' => 90,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        StockBalance::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $samsung->id,
            'quantity_available' => 2,
            'quantity_reserved' => 1,
        ]);

        StockBalance::create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $samsung->id,
            'quantity_available' => 3,
            'quantity_reserved' => 1,
        ]);

        StockBalance::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $audifonos->id,
            'quantity_available' => 12,
            'quantity_damaged' => 1,
        ]);
    }

    private function inventoryUser(Tenant $tenant, array $permissions = ['products.view', 'inventory.view']): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $user, 'Inventario '.md5(implode('|', $permissions)), $permissions);

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
