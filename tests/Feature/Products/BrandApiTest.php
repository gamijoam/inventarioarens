<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Products\Models\Brand;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BrandApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('products.view', 'web');
        Permission::findOrCreate('products.create', 'web');
        Permission::findOrCreate('products.update', 'web');
        Permission::findOrCreate('products.delete', 'web');
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }

    private function tenant(): Tenant
    {
        $tenant = Tenant::create(['name' => 'Test Co', 'slug' => 'test-co']);
        $this->useTenant($tenant);

        return $tenant;
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.test',
            'password' => bcrypt('secret123'),
        ]);
        $user->tenants()->attach($tenant->id, ['status' => 'active']);
        setPermissionsTeamId($tenant->id);
        $user->givePermissionTo(['products.view', 'products.create', 'products.update', 'products.delete']);

        return $user;
    }

    public function test_can_create_brand(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/brands', [
                'name' => 'Samsung',
                'slug' => 'samsung',
                'description' => 'Marca surcoreana',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Samsung')
            ->assertJsonPath('data.slug', 'samsung')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('brands', [
            'tenant_id' => $tenant->id,
            'slug' => 'samsung',
            'name' => 'Samsung',
        ]);
    }

    public function test_brand_slug_must_be_lowercase(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/brands', [
                'name' => 'Test',
                'slug' => 'Invalid Slug',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_can_list_brands_with_search(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);
        Brand::create(['tenant_id' => $tenant->id, 'name' => 'Apple', 'slug' => 'apple']);
        Brand::create(['tenant_id' => $tenant->id, 'name' => 'Samsung', 'slug' => 'samsung']);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/brands?search=sams');

        $response->assertOk()
            ->assertJsonPath('data.0.slug', 'samsung');
    }

    public function test_can_update_brand(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'Old', 'slug' => 'old']);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/brands/{$brand->id}", [
                'name' => 'New Name',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_can_delete_brand(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'X', 'slug' => 'x']);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/brands/{$brand->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
    }

    public function test_brand_is_tenant_isolated(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a']);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b']);
        $adminA = $this->admin($tenantA);

        $this->useTenant($tenantB);
        Brand::create(['name' => 'Hidden', 'slug' => 'hidden']);
        $this->useTenant($tenantA);

        $response = $this
            ->actingAs($adminA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/brands');

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertNotContains('hidden', $slugs);
    }
}
