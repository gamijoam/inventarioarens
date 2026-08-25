<?php

namespace Tests\Feature\AccessControl;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserAuditApiTest extends TestCase
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

    public function test_audit_route_rejects_a_tenant_that_differs_from_the_authenticated_context(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'tenant-a', 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'tenant-b', 'status' => 'active']);
        $actor = User::factory()->create(['password' => 'secret123']);
        $target = User::factory()->create();
        $actor->tenants()->attach($tenantA, ['status' => 'active']);
        $target->tenants()->attach($tenantB, ['status' => 'active']);

        $token = $this->tokenFor($actor, $tenantA, ['users.view']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson("/api/tenants/{$tenantB->id}/users/{$target->id}/audits")
            ->assertForbidden();
    }

    public function test_audit_route_allows_the_authenticated_tenant_context(): void
    {
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'tenant-a', 'status' => 'active']);
        $actor = User::factory()->create(['password' => 'secret123']);
        $target = User::factory()->create();
        $actor->tenants()->attach($tenant, ['status' => 'active']);
        $target->tenants()->attach($tenant, ['status' => 'active']);

        $token = $this->tokenFor($actor, $tenant, ['users.view']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/tenants/{$tenant->id}/users/{$target->id}/audits")
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    private function tokenFor(User $user, Tenant $tenant, array $permissions): string
    {
        setPermissionsTeamId($tenant->id);
        $role = Role::create([
            'name' => 'AuditActor-'.uniqid(),
            'guard_name' => 'web',
            config('permission.column_names.team_foreign_key', 'team_id') => $tenant->id,
        ]);
        $role->syncPermissions($permissions);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

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
