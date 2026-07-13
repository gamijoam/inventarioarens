<?php

namespace Tests\Feature\AccessControl;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedTenantContext(Tenant $tenant): void
    {
        app(\App\Support\Tenancy\TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (\App\Support\Permissions\BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_catalog_returns_hierarchical_tree_with_all_permissions(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedTenantContext($tenant);
        $user->givePermissionTo('users.view');
        $token = $this->makeTokenFor($user, $tenant);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/permission-catalog')
            ->assertOk()
            ->json();

        $expected = count(\App\Support\Permissions\BasePermissions::PERMISSIONS);
        $this->assertSame($expected, $response['data']['total_permissions']);
        $this->assertGreaterThan(20, $response['data']['total_modules']);
        $this->assertCount($response['data']['total_modules'], $response['data']['modules']);

        $salesModule = collect($response['data']['modules'])->firstWhere('module', 'sales');
        $this->assertNotNull($salesModule);
        $this->assertSame('Ventas', $salesModule['label']);
        $this->assertSame(3, $salesModule['verb_count']);

        $cancelAction = collect($salesModule['actions'])->firstWhere('verb', 'cancel');
        $this->assertNotNull($cancelAction);
        $this->assertSame('sales.cancel', $cancelAction['permission']);
        $this->assertSame('high', $cancelAction['danger']);

        $viewAction = collect($salesModule['actions'])->firstWhere('verb', 'view');
        $this->assertArrayNotHasKey('danger', $viewAction);
    }

    public function test_catalog_requires_users_view_or_roles_view(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $token = $this->makeTokenFor($user, $tenant);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/permission-catalog')
            ->assertForbidden();
    }

    public function test_legacy_permissions_endpoint_still_works(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedTenantContext($tenant);
        $user->givePermissionTo('users.view');
        $token = $this->makeTokenFor($user, $tenant);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/permissions')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('data', $response);
        $this->assertNotEmpty($response['data']);
        $first = $response['data'][0];
        $this->assertArrayHasKey('module', $first);
        $this->assertArrayHasKey('permissions', $first);
    }

    public function test_duplicate_role_clones_permissions_into_new_role(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedTenantContext($tenant);
        $user->givePermissionTo('roles.create');
        $token = $this->makeTokenFor($user, $tenant);

        $source = Role::create(['name' => 'Cajero Original', 'guard_name' => 'web', 'team_id' => $tenant->id]);
        $source->syncPermissions(['sales.view', 'sales.create', 'pos.view', 'pos.checkout']);
        $source->load('permissions');
        dump('Source permissions:', $source->permissions->pluck('name')->all());

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/roles/{$source->id}/duplicate", [
                'name' => 'Cajero Senior',
            ])
            ->assertCreated()
            ->json();

        $newId = $response['data']['id'];
        $this->assertNotSame($source->id, $newId);

        $newRole = Role::query()->findOrFail($newId);
        $newRole->load('permissions');
        $this->assertSame('Cajero Senior', $newRole->name);
        $this->assertSame($tenant->id, $newRole->getAttribute(config('permission.column_names.team_foreign_key', 'team_id')));
        $expectedPermissions = ['sales.create', 'sales.view', 'pos.checkout', 'pos.view'];
        sort($expectedPermissions);
        $actualPermissions = $newRole->permissions->pluck('name')->all();
        sort($actualPermissions);
        $this->assertSame($expectedPermissions, $actualPermissions);
    }

    public function test_duplicate_role_rejects_duplicate_name_in_same_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedTenantContext($tenant);
        $user->givePermissionTo('roles.create');
        $token = $this->makeTokenFor($user, $tenant);

        $source = Role::create(['name' => 'Source', 'guard_name' => 'web', 'team_id' => $tenant->id]);
        $existing = Role::create(['name' => 'Duplicado', 'guard_name' => 'web', 'team_id' => $tenant->id]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/roles/{$source->id}/duplicate", [
                'name' => 'Duplicado',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_duplicate_role_cross_tenant_returns_404(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active']);

        // Crear el source directamente en la DB sin pasar por Spatie (que sobreescribe team_id).
        $source = new Role();
        $source->forceFill([
            'name' => 'Source',
            'guard_name' => 'web',
            config('permission.column_names.team_foreign_key', 'team_id') => $tenantA->id,
        ])->save();

        // User en tenantB con permisos.
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenantB, ['status' => 'active']);
        $this->seedTenantContext($tenantB);
        $user->givePermissionTo('roles.create');
        $token = $this->makeTokenFor($user, $tenantB);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->postJson("/api/roles/{$source->id}/duplicate", [
                'name' => 'Copia',
            ])
            ->assertStatus(404);
    }

    public function test_preview_returns_correct_counts_and_module_list(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedTenantContext($tenant);
        $user->givePermissionTo('roles.view');
        $token = $this->makeTokenFor($user, $tenant);

        $role = Role::create(['name' => 'Multi', 'guard_name' => 'web', 'team_id' => $tenant->id]);
        $role->syncPermissions(['sales.view', 'sales.create', 'pos.view', 'inventory.view']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/roles/{$role->id}/preview")
            ->assertOk()
            ->json();

        $this->assertSame($role->id, $response['data']['role_id']);
        $this->assertSame('Multi', $response['data']['name']);
        $this->assertSame(4, $response['data']['permission_count']);
        $this->assertSame(3, $response['data']['module_count']);
        $this->assertEqualsCanonicalizing(['sales', 'pos', 'inventory'], $response['data']['modules']);
        $this->assertFalse($response['data']['protected']);
    }

    public function test_preview_marks_protected_role(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedTenantContext($tenant);
        $user->givePermissionTo('roles.view');
        $token = $this->makeTokenFor($user, $tenant);

        $role = Role::create(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $tenant->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/roles/{$role->id}/preview")
            ->assertOk()
            ->json();

        $this->assertTrue($response['data']['protected']);
    }

    public function test_audit_log_records_role_duplication(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedTenantContext($tenant);
        $user->givePermissionTo('roles.create');
        $token = $this->makeTokenFor($user, $tenant);

        $source = Role::create(['name' => 'Src', 'guard_name' => 'web', 'team_id' => $tenant->id]);
        $source->syncPermissions(['sales.view']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/roles/{$source->id}/duplicate", ['name' => 'Copy'])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'access.role.duplicated']);
    }

    private function makeTokenFor(User $user, Tenant $tenant): string
    {
        $plainToken = Str::random(80);
        AuthToken::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $plainToken;
    }
}
