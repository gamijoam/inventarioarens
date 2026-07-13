<?php

namespace Tests\Feature\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Services\ScopeResolver;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ScopeFilteringIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (\App\Support\Permissions\BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_effective_permissions_includes_scopes_object(): void
    {
        $tenant = $this->createTenant();
        app(\App\Support\Tenancy\TenantManager::class)->set($tenant);
        $user = $this->createUserWithPermissions($tenant, ['users.view']);

        $b1 = \App\Modules\Branches\Models\Branch::create(['name' => 'B1', 'code' => 'B1', 'tenant_id' => $tenant->id]);
        $b2 = \App\Modules\Branches\Models\Branch::create(['name' => 'B2', 'code' => 'B2', 'tenant_id' => $tenant->id]);

        \App\Modules\AccessControl\Models\UserBranchScope::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'branch_id' => $b1->id,
        ]);
        \App\Modules\AccessControl\Models\UserBranchScope::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'branch_id' => $b2->id,
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/tenants/{$tenant->id}/users/{$user->id}/effective-permissions")
            ->assertOk()
            ->json();

        $this->assertSame('restrict', $response['data']['scope_status']);
        $this->assertSame([$b1->id, $b2->id], $response['data']['scopes']['branches']);
        $this->assertSame(2, $response['data']['scopes']['branches_count']);
        $this->assertSame([], $response['data']['scopes']['warehouses']);
        $this->assertSame([], $response['data']['scopes']['customer_groups']);
        $this->assertSame([], $response['data']['scopes']['vendor_of']);
    }

    public function test_effective_permissions_with_no_scope(): void
    {
        $tenant = $this->createTenant();
        app(\App\Support\Tenancy\TenantManager::class)->set($tenant);
        $user = $this->createUserWithPermissions($tenant, ['users.view']);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/tenants/{$tenant->id}/users/{$user->id}/effective-permissions")
            ->assertOk()
            ->json();

        $this->assertSame('none', $response['data']['scope_status']);
        $this->assertSame([], $response['data']['scopes']['branches']);
        $this->assertSame([], $response['data']['scopes']['warehouses']);
        $this->assertSame([], $response['data']['scopes']['customer_groups']);
        $this->assertSame([], $response['data']['scopes']['vendor_of']);
        $this->assertSame(0, $response['data']['scopes']['branches_count']);
    }

    public function test_scope_status_via_capability_resolver(): void
    {
        $tenant = $this->createTenant();
        app(\App\Support\Tenancy\TenantManager::class)->set($tenant);

        $user1 = $this->createUserWithPermissions($tenant, ['users.view']);
        $user2 = $this->createUserWithPermissions($tenant, ['users.view']);
        $b1 = \App\Modules\Branches\Models\Branch::create(['name' => 'B1', 'code' => 'B1', 'tenant_id' => $tenant->id]);

        \App\Modules\AccessControl\Models\UserBranchScope::create([
            'tenant_id' => $tenant->id, 'user_id' => $user2->id, 'branch_id' => $b1->id,
        ]);

        $resolver = app(ScopeResolver::class);
        $this->assertSame('none', $resolver->statusFor($user1));
        $this->assertSame('restrict', $resolver->statusFor($user2));
    }

    private function createTenant(): Tenant
    {
        return Tenant::create(['name' => 'T', 'slug' => 't-' . \Illuminate\Support\Str::random(6), 'status' => 'active']);
    }

    private function createUserWithPermissions(Tenant $tenant, array $permissions): User
    {
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');
        setPermissionsTeamId($tenant->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = \Spatie\Permission\Models\Role::create([
            'name' => 'Test-' . \Illuminate\Support\Str::random(4),
            'guard_name' => 'web',
            $teamColumn => $tenant->id,
        ]);
        $perms = Permission::query()
            ->whereIn('name', $permissions)
            ->where('guard_name', 'web')
            ->get();
        $role->syncPermissions($perms);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $user;
    }
}