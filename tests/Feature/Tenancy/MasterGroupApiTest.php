<?php

namespace Tests\Feature\Tenancy;

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

class MasterGroupApiTest extends TestCase
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

    public function test_unauthenticated_user_cannot_create_group(): void
    {
        $this->postJson('/api/master/groups', [])->assertUnauthorized();
    }

    public function test_non_platform_admin_cannot_create_group(): void
    {
        $token = $this->makeRegularUserToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/master/groups', [
                'name' => 'Arens Holding',
                'slug' => 'arens-holding',
                'group_owner' => ['name' => 'Boss', 'email' => 'boss@arens.test'],
            ])
            ->assertForbidden();
    }

    public function test_platform_admin_can_create_group_with_owner(): void
    {
        $token = $this->makePlatformAdminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/master/groups', [
                'name' => 'Arens Holding',
                'slug' => 'arens-holding',
                'plan' => 'enterprise',
                'group_owner' => [
                    'name' => 'Jefe Holding',
                    'email' => 'jefe@arens.test',
                    'password' => 'Secret123',
                ],
            ])
            ->assertCreated()
            ->json();

        $this->assertSame('arens-holding', $response['data']['slug']);
        $this->assertTrue($response['data']['is_group']);
        $this->assertNull($response['data']['parent_id']);

        $group = Tenant::where('slug', 'arens-holding')->first();
        $this->assertNotNull($group);
        $this->assertNull($group->parent_id);
        $this->assertTrue($group->isGroup());

        $owner = User::where('email', 'jefe@arens.test')->first();
        $this->assertNotNull($owner);
        $this->assertTrue(
            $owner->tenants()->whereKey($group->id)->wherePivot('status', 'active')->exists()
        );
    }

    public function test_group_owner_is_assigned_owner_role_with_all_permissions(): void
    {
        $token = $this->makePlatformAdminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/master/groups', [
                'name' => 'Group With Owner Role',
                'slug' => 'group-owner-role',
                'group_owner' => ['name' => 'Boss', 'email' => 'boss@role.test', 'password' => 'Secret123'],
            ])
            ->assertCreated();

        $group = Tenant::where('slug', 'group-owner-role')->first();
        $owner = User::where('email', 'boss@role.test')->first();

        setPermissionsTeamId($group->id);
        $this->assertTrue($owner->hasRole('Owner'));

        $ownerRole = Role::where('name', 'Owner')
            ->where(config('permission.column_names.team_foreign_key', 'team_id'), $group->id)
            ->first();
        $this->assertGreaterThan(80, $ownerRole->permissions->count());
    }

    public function test_platform_admin_can_list_groups(): void
    {
        Tenant::create(['name' => 'Grupo A', 'slug' => 'grupo-a', 'status' => 'active']);
        Tenant::create(['name' => 'Spinoff', 'slug' => 'spinoff-x', 'status' => 'active', 'parent_id' => null]);

        $token = $this->makePlatformAdminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/master/groups')
            ->assertOk()
            ->json();

        $slugs = array_column($response['data'], 'slug');
        $this->assertContains('grupo-a', $slugs);
        $this->assertContains('spinoff-x', $slugs);
    }

    public function test_audit_log_records_group_creation(): void
    {
        $token = $this->makePlatformAdminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/master/groups', [
                'name' => 'Audited Group',
                'slug' => 'audited-group',
                'group_owner' => ['name' => 'A', 'email' => 'a@b.test'],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant_group.created',
        ]);
    }

    public function test_duplicate_slug_rejected(): void
    {
        Tenant::create(['name' => 'Existing', 'slug' => 'dup-slug', 'status' => 'active']);
        $token = $this->makePlatformAdminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/master/groups', [
                'name' => 'New',
                'slug' => 'dup-slug',
                'group_owner' => ['name' => 'A', 'email' => 'a@b.test'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_platform_admin_can_create_spinoff_under_group(): void
    {
        $token = $this->makePlatformAdminToken();
        $group = Tenant::create([
            'name' => 'Arens Holding',
            'slug' => 'arens-holding',
            'status' => 'active',
            'plan' => 'enterprise',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/master/groups/{$group->slug}/tenants", [
                'name' => 'Arens Valencia',
                'slug' => 'arens-valencia',
                'plan' => 'premium',
                'admin' => [
                    'name' => 'Admin Valencia',
                    'email' => 'admin.valencia@arens.test',
                    'password' => 'Secret123',
                ],
                'branch' => ['name' => 'Valencia', 'code' => 'VAL'],
                'warehouse' => ['name' => 'Almacen Valencia', 'code' => 'VAL-01'],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.slug', 'arens-valencia');
        $response->assertJsonPath('data.parent_id', $group->id);
        $response->assertJsonPath('data.is_group', false);

        $this->assertDatabaseHas('tenants', [
            'slug' => 'arens-valencia',
            'parent_id' => $group->id,
            'plan' => 'premium',
            'status' => 'active',
        ]);
    }

    public function test_regular_user_cannot_create_spinoff_via_master_route(): void
    {
        $token = $this->makeRegularUserToken();
        $group = Tenant::create([
            'name' => 'Arens Holding',
            'slug' => 'arens-holding',
            'status' => 'active',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/master/groups/{$group->slug}/tenants", [
                'name' => 'Arens Valencia',
                'slug' => 'arens-valencia',
                'admin' => ['name' => 'Admin', 'email' => 'admin@arens.test'],
            ])
            ->assertForbidden();
    }

    public function test_create_spinoff_rejects_non_group_tenant(): void
    {
        $token = $this->makePlatformAdminToken();
        $parent = Tenant::create([
            'name' => 'Parent',
            'slug' => 'parent-group',
            'status' => 'active',
        ]);
        $spinoff = Tenant::create([
            'name' => 'Already a spinoff',
            'slug' => 'already-spin',
            'status' => 'active',
            'parent_id' => $parent->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/master/groups/{$spinoff->slug}/tenants", [
                'name' => 'New Valencia',
                'slug' => 'new-valencia',
                'admin' => ['name' => 'Admin', 'email' => 'admin@arens.test'],
            ])
            ->assertNotFound();
    }

    public function test_create_spinoff_rejects_duplicate_slug(): void
    {
        $token = $this->makePlatformAdminToken();
        $group = Tenant::create([
            'name' => 'Arens Holding',
            'slug' => 'arens-holding',
            'status' => 'active',
        ]);
        Tenant::create(['name' => 'Existing', 'slug' => 'existing-slug', 'status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/master/groups/{$group->slug}/tenants", [
                'name' => 'New Valencia',
                'slug' => 'existing-slug',
                'admin' => ['name' => 'Admin', 'email' => 'admin@arens.test'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    private function makePlatformAdminToken(): string
    {
        $admin = User::factory()->create([
            'password' => 'secret123',
            'is_platform_admin' => true,
        ]);

        $plainToken = Str::random(80);
        AuthToken::create([
            'tenant_id' => null,
            'user_id' => $admin->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $plainToken;
    }

    private function makeRegularUserToken(): string
    {
        $tenant = Tenant::create(['name' => 'Host', 'slug' => 'host', 'status' => 'active']);
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenant, ['status' => 'active']);

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
