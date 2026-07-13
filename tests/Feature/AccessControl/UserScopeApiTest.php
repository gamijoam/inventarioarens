<?php

namespace Tests\Feature\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\UserBranchScope;
use App\Modules\AccessControl\Models\UserCustomerGroupScope;
use App\Modules\AccessControl\Models\UserVendorAssignment;
use App\Modules\AccessControl\Models\UserWarehouseScope;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserScopeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_show_returns_empty_scopes_when_unset(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $admin->givePermissionTo('users.view');
        $token = $this->makeTokenFor($admin, $tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/tenants/{$tenant->id}/users/{$user->id}/scopes")
            ->assertOk()
            ->json();

        $this->assertSame([], $response['data']['branches']);
        $this->assertSame([], $response['data']['warehouses']);
        $this->assertSame([], $response['data']['customer_groups']);
        $this->assertSame([], $response['data']['vendor_of']);
    }

    public function test_replace_branches_is_idempotent(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $admin->givePermissionTo('users.update');
        $token = $this->makeTokenFor($admin, $tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $branch1 = Branch::create(['name' => 'B1', 'code' => 'B1', 'tenant_id' => $tenant->id]);
        $branch2 = Branch::create(['name' => 'B2', 'code' => 'B2', 'tenant_id' => $tenant->id]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/tenants/{$tenant->id}/users/{$user->id}/scopes/branches", [
                'branch_ids' => [$branch1->id, $branch2->id],
            ])
            ->assertNoContent();

        $this->assertDatabaseCount('user_branch_scopes', 2);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/tenants/{$tenant->id}/users/{$user->id}/scopes/branches", [
                'branch_ids' => [$branch1->id],
            ])
            ->assertNoContent();

        $this->assertDatabaseCount('user_branch_scopes', 1);
        $this->assertDatabaseHas('user_branch_scopes', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'branch_id' => $branch1->id,
        ]);
    }

    public function test_replace_warehouses_creates_user_warehouse_scopes(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $admin->givePermissionTo('users.update');
        $token = $this->makeTokenFor($admin, $tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $branch = Branch::create(['name' => 'B1', 'code' => 'B1', 'tenant_id' => $tenant->id]);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'W1',
            'code' => 'W1',
            'tenant_id' => $tenant->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/tenants/{$tenant->id}/users/{$user->id}/scopes/warehouses", [
                'warehouse_ids' => [$warehouse->id],
            ])
            ->assertNoContent();

        $this->assertDatabaseHas('user_warehouse_scopes', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'warehouse_id' => $warehouse->id,
        ]);
    }

    public function test_replace_customer_groups_creates_user_customer_group_scopes(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $admin->givePermissionTo('users.update');
        $token = $this->makeTokenFor($admin, $tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $group = CustomerGroup::create([
            'tenant_id' => $tenant->id,
            'code' => 'CG1',
            'name' => 'Retail',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/tenants/{$tenant->id}/users/{$user->id}/scopes/customer-groups", [
                'customer_group_ids' => [$group->id],
            ])
            ->assertNoContent();

        $this->assertDatabaseHas('user_customer_group_scopes', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'customer_group_id' => $group->id,
        ]);
    }

    public function test_replace_vendor_of_creates_user_vendor_assignments(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $admin->givePermissionTo('users.update');
        $token = $this->makeTokenFor($admin, $tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $group = CustomerGroup::create([
            'tenant_id' => $tenant->id,
            'code' => 'VENDOR',
            'name' => 'Vendor Group',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/tenants/{$tenant->id}/users/{$user->id}/scopes/vendor-of", [
                'customer_group_ids' => [$group->id],
            ])
            ->assertNoContent();

        $this->assertDatabaseHas('user_vendor_assignments', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'customer_group_id' => $group->id,
        ]);
    }

    public function test_show_returns_expanded_scope_objects(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $admin->givePermissionTo('users.view');
        $token = $this->makeTokenFor($admin, $tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $branch = Branch::create(['name' => 'Valencia', 'code' => 'VAL', 'tenant_id' => $tenant->id]);
        UserBranchScope::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'branch_id' => $branch->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/tenants/{$tenant->id}/users/{$user->id}/scopes")
            ->assertOk()
            ->json();

        $this->assertCount(1, $response['data']['branches']);
        $this->assertSame($branch->id, $response['data']['branches'][0]);
        $this->assertCount(1, $response['data']['expanded']['branches']);
        $this->assertSame('Valencia', $response['data']['expanded']['branches'][0]['name']);
    }

    public function test_replace_branches_requires_users_update(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $viewer = User::factory()->create(['password' => 'secret123']);
        $viewer->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $viewer->givePermissionTo('users.view');
        $token = $this->makeTokenFor($viewer, $tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/tenants/{$tenant->id}/users/{$user->id}/scopes/branches", [
                'branch_ids' => [],
            ])
            ->assertForbidden();
    }

    public function test_cross_tenant_scope_assignment_returns_404(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active']);

        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenantB, ['status' => 'active']);
        $this->seedPermissions($tenantB);
        $admin->givePermissionTo('users.update');
        $token = $this->makeTokenFor($admin, $tenantB);

        $other = User::factory()->create();
        $other->tenants()->attach($tenantA, ['status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->putJson("/api/tenants/{$tenantB->id}/users/{$other->id}/scopes/branches", [
                'branch_ids' => [],
            ])
            ->assertStatus(404);
    }

    public function test_replace_all_updates_every_scope_at_once(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $admin->givePermissionTo('users.update');
        $token = $this->makeTokenFor($admin, $tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $branch = Branch::create(['name' => 'B1', 'code' => 'B1', 'tenant_id' => $tenant->id]);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'W1',
            'code' => 'W1',
            'tenant_id' => $tenant->id,
        ]);
        $group = CustomerGroup::create([
            'tenant_id' => $tenant->id,
            'code' => 'CG1',
            'name' => 'Retail',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/tenants/{$tenant->id}/users/{$user->id}/scopes", [
                'branch_ids' => [$branch->id],
                'warehouse_ids' => [$warehouse->id],
                'customer_group_ids' => [$group->id],
            ])
            ->assertOk()
            ->json();

        $this->assertDatabaseCount('user_branch_scopes', 1);
        $this->assertDatabaseCount('user_warehouse_scopes', 1);
        $this->assertDatabaseCount('user_customer_group_scopes', 1);
        $this->assertDatabaseCount('user_vendor_assignments', 1);
    }

    public function test_audit_log_records_scope_assigned(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->tenants()->attach($tenant, ['status' => 'active']);
        $this->seedPermissions($tenant);
        $admin->givePermissionTo('users.update');
        $token = $this->makeTokenFor($admin, $tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $branch = Branch::create(['name' => 'B1', 'code' => 'B1', 'tenant_id' => $tenant->id]);
        UserBranchScope::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'branch_id' => $branch->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/tenants/{$tenant->id}/users/{$user->id}/scopes/branches", [
                'branch_ids' => [$branch->id],
            ])
            ->assertNoContent();

        $this->assertDatabaseHas('audit_logs', ['action' => 'access.user.scope_assigned']);
    }

    private function seedPermissions(Tenant $tenant): void
    {
        app(\App\Support\Tenancy\TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (\App\Support\Permissions\BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
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