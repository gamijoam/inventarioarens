<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Products\Models\Category;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('products.view', 'web');
        Permission::findOrCreate('products.create', 'web');
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
        $user->givePermissionTo(['products.view', 'products.create']);

        return $user;
    }

    public function test_can_create_root_category(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/categories', [
                'name' => 'Electronica',
                'slug' => 'electronica',
                'sort_order' => 1,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Electronica')
            ->assertJsonPath('data.parent_id', null);
    }

    public function test_can_create_child_category(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);
        $root = Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Electronica',
            'slug' => 'electronica',
        ]);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/categories', [
                'name' => 'Celulares',
                'slug' => 'celulares',
                'parent_id' => $root->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.parent_id', $root->id);
    }

    public function test_can_get_category_tree(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);
        $root = Category::create(['tenant_id' => $tenant->id, 'name' => 'Electronica', 'slug' => 'electronica']);
        Category::create(['tenant_id' => $tenant->id, 'name' => 'Celulares', 'slug' => 'celulares', 'parent_id' => $root->id]);
        Category::create(['tenant_id' => $tenant->id, 'name' => 'Ropa', 'slug' => 'ropa']);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/categories/tree');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $first = $response->json('data.0');
        $this->assertSame('Electronica', $first['name']);
        $this->assertCount(1, $first['children']);
    }

    public function test_roots_only_filter(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);
        $root = Category::create(['tenant_id' => $tenant->id, 'name' => 'Root', 'slug' => 'root']);
        Category::create(['tenant_id' => $tenant->id, 'name' => 'Child', 'slug' => 'child', 'parent_id' => $root->id]);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/categories?roots_only=1');

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Root', $response->json('data.0.name'));
    }
}
