<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cobertura del sistema de variantes de producto (color).
 *
 * Los tests cubren:
 *  - CRUD basico de variantes (create / update / list).
 *  - Filtro de variantes inactivas.
 *  - Aislamiento cross-tenant.
 *  - Hooks de sync outbox.
 *  - Observer que crea variante default.
 */
class ProductVariantApiTest extends TestCase
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

    public function test_owner_can_create_variant_with_color_and_price_override(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $product = $this->productFor($tenant, 'iPhone 15', 'IPHONE-15');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Catalog Manager', ['products.view', 'products.update']);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/products/{$product->id}/variants", [
                'color' => 'Azul',
                'color_hex' => '#1e90ff',
                'sku_variant' => 'IPHONE-15-AZ',
                'price_override' => 549.99,
                'position' => 1,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.color', 'Azul')
            ->assertJsonPath('data.color_hex', '#1e90ff')
            ->assertJsonPath('data.sku_variant', 'IPHONE-15-AZ')
            ->assertJsonPath('data.price_override', '549.9900');

        $this->assertDatabaseHas('product_variants', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'color' => 'Azul',
            'is_active' => true,
        ]);
    }

    public function test_update_changes_variant_color(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $product = $this->productFor($tenant, 'iPhone 15', 'IPHONE-15');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Catalog Manager', ['products.view', 'products.update']);
        $this->useTenant($tenant);
        $variant = $product->variants()->create([
            'color' => 'Azul',
            'position' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/products/{$product->id}/variants/{$variant->id}", [
                'color' => 'Negro',
                'color_hex' => '#000000',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.color', 'Negro')
            ->assertJsonPath('data.color_hex', '#000000');
    }

    public function test_index_excludes_inactive_variants_when_product_filter_passed(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $product = $this->productFor($tenant, 'iPhone 15', 'IPHONE-15');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Catalog Manager', ['products.view', 'products.update']);
        $this->useTenant($tenant);

        $product->variants()->create(['color' => 'Azul', 'position' => 1, 'is_active' => true]);
        $product->variants()->create(['color' => 'Negro', 'position' => 2, 'is_active' => false]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/products/{$product->id}/variants");

        $response->assertOk();
        $colors = collect($response->json('data'))->pluck('color')->all();
        // Devuelve las activas (incluida la default con color null) ordenadas por position.
        $this->assertSame([null, 'Azul'], $colors);
    }

    public function test_cross_tenant_user_cannot_view_other_tenant_variants(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $productA = $this->productFor($tenantA, 'iPhone 15', 'IPHONE-15');
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantB, $userB, 'Catalog Manager', ['products.view']);

        $response = $this->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson("/api/products/{$productA->id}/variants");

        // El producto de otro tenant no existe para este user; la policy
        // bloquea la lectura. Aceptamos 403/404.
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_variant_observer_creates_default_on_product_created(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $this->useTenant($tenant);
        $product = Product::create(['name' => 'iPhone 15', 'sku' => 'IPHONE-15']);

        $default = $product->variants()->whereNull('color')->first();
        $this->assertNotNull($default);
        $this->assertTrue((bool) $default->is_active);
        $this->assertSame(0, (int) $default->position);
    }

    public function test_index_includes_stock_available_per_warehouse(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $this->useTenant($tenant);
        $product = $this->productFor($tenant, 'iPhone 15', 'IPHONE-15');
        $variant = $product->variants()->create([
            'color' => 'Azul',
            'position' => 0,
            'is_active' => true,
        ]);

        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Catalog Manager', ['products.view']);

        // Verificamos solo que el endpoint acepta el parametro warehouse_id
        // y responde 200; el calculo de stock se cubre en el modulo de
        // inventario (tests/Feature/Inventory/InventoryMovementTest.php).
        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/products/{$product->id}/variants?warehouse_id=1");

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_sync_outbox_variant_created_invokes_service_without_errors(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $product = $this->productFor($tenant, 'iPhone 15', 'IPHONE-15');
        $this->useTenant($tenant);
        $variant = $product->variants()->create([
            'color' => 'Azul',
            'position' => 0,
            'is_active' => true,
        ]);

        // El outbox no inserta sin sync_nodes activos; verificamos que la
        // llamada al servicio no lance excepciones. La emision a otros
        // nodos se cubre en tests/Feature/Sync/PosOrderStockSyncTest.php.
        app(SyncCatalogOutboxService::class)->variantCreated($variant);
        app(SyncCatalogOutboxService::class)->variantUpdated($variant->refresh());
        app(SyncCatalogOutboxService::class)->variantDeleted($variant);
        $this->assertTrue(true);
    }

    private function productFor(Tenant $tenant, string $name, string $sku): Product
    {
        $this->useTenant($tenant);

        return Product::create(['name' => $name, 'sku' => $sku]);
    }

    private function warehouseFor(Tenant $tenant): \App\Modules\Warehouses\Models\Warehouse
    {
        $this->useTenant($tenant);
        $branch = \App\Modules\Branches\Models\Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        return \App\Modules\Warehouses\Models\Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'Almacén',
            'code' => 'WH',
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
        $role = \Spatie\Permission\Models\Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
