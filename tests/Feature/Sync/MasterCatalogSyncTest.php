<?php

namespace Tests\Feature\Sync;

use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\Tag;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warranties\Models\WarrantyPolicy;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MasterCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_outbox_emits_warranty_and_supplier_events(): void
    {
        $tenant = $this->tenant('grupo-maestro');
        $this->useTenant($tenant);

        $warranty = WarrantyPolicy::create([
            'name' => 'Garantia 12 meses',
            'duration_days' => 365,
            'coverage_type' => WarrantyPolicy::COVERAGE_STORE,
            'is_active' => true,
        ]);
        $supplier = Supplier::create([
            'name' => 'Proveedor Central',
            'document_type' => Supplier::DOCUMENT_J,
            'document_number' => 'J-123',
            'is_active' => true,
        ]);

        $catalog = app(SyncCatalogOutboxService::class);
        $catalog->warrantyPolicyCreated($warranty);
        $catalog->supplierCreated($supplier);

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'warranty_policy.created',
            'aggregate_type' => 'warranty_policy',
            'aggregate_id' => $warranty->id,
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'supplier.created',
            'aggregate_type' => 'supplier',
            'aggregate_id' => $supplier->id,
        ]);
    }

    public function test_catalog_outbox_emits_all_remaining_shared_catalog_events(): void
    {
        $tenant = $this->tenant('grupo-catalogo');
        $this->useTenant($tenant);

        $brand = Brand::create(['slug' => 'samsung', 'name' => 'Samsung', 'is_active' => true]);
        $parent = Category::create(['slug' => 'electronica', 'name' => 'Electronica', 'is_active' => true]);
        $category = Category::create(['slug' => 'celulares', 'name' => 'Celulares', 'parent_id' => $parent->id, 'is_active' => true]);
        $tag = Tag::create(['slug' => 'nuevo', 'name' => 'Nuevo', 'color' => '#00FF00']);
        $method = PaymentMethod::create([
            'code' => 'CASH', 'name' => 'Efectivo', 'method' => 'cash',
            'currency_mode' => PaymentMethod::CURRENCY_USD, 'is_active' => true,
        ]);

        $catalog = app(SyncCatalogOutboxService::class);
        $catalog->brandCreated($brand);
        $catalog->categoryCreated($category);
        $catalog->tagCreated($tag);
        $catalog->paymentMethodCreated($method);

        foreach ([
            ['event_type' => 'brand.created', 'aggregate_type' => 'brand', 'aggregate_id' => $brand->id],
            ['event_type' => 'category.created', 'aggregate_type' => 'category', 'aggregate_id' => $category->id],
            ['event_type' => 'tag.created', 'aggregate_type' => 'tag', 'aggregate_id' => $tag->id],
            ['event_type' => 'payment_method.created', 'aggregate_type' => 'payment_method', 'aggregate_id' => $method->id],
        ] as $event) {
            $this->assertDatabaseHas('sync_outbox', array_merge(['tenant_id' => $tenant->id], $event));
        }
    }

    public function test_local_applier_creates_and_updates_warranty_and_supplier_events(): void
    {
        $tenant = $this->tenant('tienda-local');
        $now = now();

        $this->insertInbox($tenant, 'warranty_policy.created', [
            'name' => 'Garantia 30 dias',
            'duration_days' => 30,
            'coverage_type' => 'store',
            'conditions' => 'Defectos de fabrica',
            'is_active' => true,
        ], $now);
        $this->insertInbox($tenant, 'supplier.created', [
            'name' => 'Proveedor Local',
            'document_type' => 'J',
            'document_number' => 'J-456',
            'phone' => '04140000000',
            'is_active' => true,
        ], $now);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(2, $summary['applied']);
        $this->assertDatabaseHas('warranty_policies', [
            'tenant_id' => $tenant->id,
            'name' => 'Garantia 30 dias',
            'duration_days' => 30,
        ]);
        $this->assertDatabaseHas('suppliers', [
            'tenant_id' => $tenant->id,
            'document_number' => 'J-456',
        ]);
    }

    public function test_product_event_applies_catalog_fields_added_after_initial_sync(): void
    {
        $tenant = $this->tenant('tienda-productos');
        $now = now();
        DB::table('brands')->insert([
            'tenant_id' => $tenant->id,
            'slug' => 'samsung',
            'name' => 'Samsung',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('categories')->insert([
            'tenant_id' => $tenant->id,
            'slug' => 'celulares',
            'name' => 'Celulares',
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('tags')->insert([
            'tenant_id' => $tenant->id,
            'slug' => 'nuevo',
            'name' => 'Nuevo',
            'color' => '#00FF00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insertInbox($tenant, 'product.created', [
            'sku' => 'SKU-CATALOG-001',
            'name' => 'Producto completo',
            'barcode' => '7501234567890',
            'description' => 'Descripcion corta',
            'long_description' => '<p>Descripcion larga</p>',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'unit_of_measure' => Product::UNIT_UNIT,
            'track_stock' => true,
            'base_price' => '25.0000',
            'profit_margin' => '30.0000',
            'sale_currency' => 'USD',
            'min_stock' => '5.0000',
            'max_stock' => '100.0000',
            'reorder_quantity' => '20.0000',
            'brand_slug' => 'samsung',
            'category_slugs' => ['celulares'],
            'tag_slugs' => ['nuevo'],
            'is_catalog_active' => true,
            'is_active' => true,
        ], $now);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1, $summary['applied']);
        $this->assertDatabaseHas('products', [
            'tenant_id' => $tenant->id,
            'sku' => 'SKU-CATALOG-001',
            'barcode' => '7501234567890',
            'description' => 'Descripcion corta',
            'min_stock' => '5.0000',
            'max_stock' => '100.0000',
            'reorder_quantity' => '20.0000',
        ]);
        $product = Product::query()
            ->where('tenant_id', $tenant->id)
            ->where('sku', 'SKU-CATALOG-001')
            ->firstOrFail();
        $this->assertDatabaseHas('product_category', ['tenant_id' => $tenant->id, 'product_id' => $product->id]);
        $this->assertDatabaseHas('product_tag', ['tenant_id' => $tenant->id, 'product_id' => $product->id]);
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::create(['name' => $slug, 'slug' => $slug]);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }

    private function insertInbox(Tenant $tenant, string $eventType, array $payload, $now): void
    {
        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => $eventType,
            'aggregate_type' => explode('.', $eventType)[0],
            'aggregate_id' => null,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
