<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\Tag;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['products.view', 'products.create', 'products.update', 'products.delete'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }

    private function tenant(): Tenant
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->useTenant($tenant);

        return $tenant;
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@t.test',
            'password' => bcrypt('secret123'),
        ]);
        $user->tenants()->attach($tenant->id, ['status' => 'active']);
        setPermissionsTeamId($tenant->id);
        $user->givePermissionTo(['products.view', 'products.create', 'products.update', 'products.delete']);

        return $user;
    }

    public function test_can_create_product_with_all_catalog_fields(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);

        $brand = Brand::create(['name' => 'Apple', 'slug' => 'apple']);
        $category = Category::create(['name' => 'Phones', 'slug' => 'phones']);
        $tag = Tag::create(['name' => '5G', 'slug' => '5g']);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/products', [
                'name' => 'iPhone 15',
                'description' => 'Smartphone Apple',
                'long_description' => '<p>Flagship 2023</p>',
                'sku' => 'IPH15-128',
                'barcode' => '0194253714750',
                'image_url' => 'https://example.com/iphone.jpg',
                'tracking_type' => 'serialized',
                'unit_of_measure' => 'unit',
                'track_stock' => true,
                'brand_id' => $brand->id,
                'category_ids' => [$category->id],
                'tag_ids' => [$tag->id],
                'base_price' => 799.00,
                'min_stock' => 5,
                'max_stock' => 100,
                'reorder_quantity' => 50,
                'sale_currency' => 'USD',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'iPhone 15')
            ->assertJsonPath('data.sku', 'IPH15-128')
            ->assertJsonPath('data.barcode', '0194253714750')
            ->assertJsonPath('data.min_stock', 5)
            ->assertJsonPath('data.max_stock', 100)
            ->assertJsonPath('data.average_cost', null)
            ->assertJsonPath('data.brand.id', $brand->id)
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonCount(1, 'data.tags');

        $product = Product::where('sku', 'IPH15-128')->first();
        $this->assertNotNull($product);
        $this->assertTrue($product->categories->contains($category->id));
        $this->assertTrue($product->tags->contains($tag->id));
    }

    public function test_barcode_must_be_unique(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);

        Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'A',
            'sku' => 'A-1',
            'barcode' => '1111111111111',
            'tracking_type' => 'quantity',
        ]);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/products', [
                'name' => 'B',
                'sku' => 'B-1',
                'barcode' => '1111111111111',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['barcode']);
    }

    public function test_average_cost_cannot_be_set_manually(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'A',
            'sku' => 'A-1',
            'tracking_type' => 'quantity',
        ]);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/products/{$product->id}", [
                'average_cost' => 100,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['average_cost']);
    }

    public function test_search_includes_barcode(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);

        Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Coca Cola',
            'sku' => 'CC-001',
            'barcode' => '7501055309900',
            'tracking_type' => 'quantity',
        ]);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/products?search=7501055');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_filter_by_brand(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);
        $apple = Brand::create(['tenant_id' => $tenant->id, 'name' => 'Apple', 'slug' => 'apple']);
        $samsung = Brand::create(['tenant_id' => $tenant->id, 'name' => 'Samsung', 'slug' => 'samsung']);

        Product::create([
            'tenant_id' => $tenant->id, 'name' => 'iPhone', 'sku' => 'IP', 'brand_id' => $apple->id,
            'tracking_type' => 'quantity',
        ]);
        Product::create([
            'tenant_id' => $tenant->id, 'name' => 'Galaxy', 'sku' => 'GA', 'brand_id' => $samsung->id,
            'tracking_type' => 'quantity',
        ]);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/products?brand_id={$apple->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('iPhone', $response->json('data.0.name'));
    }

    public function test_filter_by_category(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);
        $phones = Category::create(['name' => 'Phones', 'slug' => 'phones']);
        $laptops = Category::create(['name' => 'Laptops', 'slug' => 'laptops']);

        $p1 = Product::create([
            'name' => 'iPhone', 'sku' => 'IP',
            'tracking_type' => 'quantity',
        ]);
        $p1->categories()->attach($phones->id, ['tenant_id' => $tenant->id]);

        $p2 = Product::create([
            'name' => 'MacBook', 'sku' => 'MB',
            'tracking_type' => 'quantity',
        ]);
        $p2->categories()->attach($laptops->id, ['tenant_id' => $tenant->id]);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/products?category_id={$phones->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_sync_categories_replaces_list(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);
        $cat1 = Category::create(['name' => 'A', 'slug' => 'a']);
        $cat2 = Category::create(['name' => 'B', 'slug' => 'b']);
        $product = Product::create([
            'name' => 'P', 'sku' => 'P-1',
            'tracking_type' => 'quantity',
        ]);
        $product->categories()->attach($cat1->id, ['tenant_id' => $tenant->id]);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/products/{$product->id}/categories", [
                'category_ids' => [$cat2->id],
            ])
            ->assertOk();

        $product->refresh();
        $this->assertFalse($product->categories->contains($cat1->id));
        $this->assertTrue($product->categories->contains($cat2->id));
    }

    public function test_unit_of_measure_validation(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/products', [
                'name' => 'X',
                'unit_of_measure' => 'invalid',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_of_measure']);
    }

    public function test_product_show_includes_new_fields(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);
        $product = Product::create([
            'name' => 'Test',
            'sku' => 'T-1',
            'barcode' => '9999999999999',
            'description' => 'Desc',
            'min_stock' => 10,
            'max_stock' => 50,
            'unit_of_measure' => 'kg',
            'tracking_type' => 'quantity',
        ]);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.barcode', '9999999999999')
            ->assertJsonPath('data.description', 'Desc')
            ->assertJsonPath('data.min_stock', 10)
            ->assertJsonPath('data.max_stock', 50)
            ->assertJsonPath('data.unit_of_measure', 'kg');
    }
}
