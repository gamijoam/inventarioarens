<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Products\Models\Tag;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TagApiTest extends TestCase
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

    public function test_can_create_tag(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/tags', [
                'name' => 'Nuevo',
                'slug' => 'nuevo',
                'color' => '#FF0000',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.color', '#FF0000');
    }

    public function test_color_must_be_hex(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);

        $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/tags', [
                'name' => 'X',
                'slug' => 'x',
                'color' => 'rojo',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['color']);
    }

    public function test_can_list_tags(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin($tenant);
        Tag::create(['tenant_id' => $tenant->id, 'name' => 'Tag1', 'slug' => 'tag1']);
        Tag::create(['tenant_id' => $tenant->id, 'name' => 'Tag2', 'slug' => 'tag2']);

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/tags');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }
}
