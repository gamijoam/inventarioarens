<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GroupSpinoffApiTest extends TestCase
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

    public function test_group_owner_can_create_spinoff_in_their_group(): void
    {
        [$groupOwner, $group, $token] = $this->makeGroupWithOwner('arens-holding');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/groups/{$group->slug}/tenants", [
                'name' => 'Arens Valencia',
                'slug' => 'arens-valencia',
                'admin' => [
                    'name' => 'Admin Valencia',
                    'email' => 'admin.valencia@arens.test',
                    'password' => 'Secret123',
                ],
                'branch' => ['name' => 'Valencia', 'code' => 'VAL'],
                'warehouse' => ['name' => 'Almacen Valencia', 'code' => 'VAL-01'],
                'exchange_rate_type' => ['code' => 'BCV', 'name' => 'Banco Central'],
            ])
            ->assertCreated()
            ->json();

        $this->assertSame('arens-valencia', $response['data']['slug']);
        $this->assertFalse($response['data']['is_group']);
        $this->assertSame($group->id, $response['data']['parent_id']);

        $spinoff = Tenant::where('slug', 'arens-valencia')->first();
        $this->assertTrue($spinoff->isSpinoff());
        $this->assertSame($group->id, $spinoff->parent_id);

        $this->assertDatabaseHas('branches', ['tenant_id' => $spinoff->id, 'code' => 'VAL']);
        $this->assertDatabaseHas('warehouses', ['tenant_id' => $spinoff->id, 'code' => 'VAL-01']);
        $this->assertDatabaseHas('exchange_rate_types', ['tenant_id' => $spinoff->id, 'code' => 'BCV']);

        $admin = User::where('email', 'admin.valencia@arens.test')->first();
        $this->assertTrue(
            $admin->tenants()->whereKey($spinoff->id)->wherePivot('status', 'active')->exists()
        );
    }

    public function test_group_owner_admin_role_has_all_permissions_in_spinoff(): void
    {
        [$groupOwner, $group, $token] = $this->makeGroupWithOwner('arens-holding');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/groups/{$group->slug}/tenants", [
                'name' => 'Spinoff Perms',
                'slug' => 'spinoff-perms',
                'admin' => ['name' => 'A', 'email' => 'a@perms.test', 'password' => 'Secret123'],
            ])
            ->assertCreated();

        $spinoff = Tenant::where('slug', 'spinoff-perms')->first();
        $admin = User::where('email', 'a@perms.test')->first();

        setPermissionsTeamId($spinoff->id);
        $this->assertTrue($admin->hasRole('Administrador'));
    }

    public function test_non_owner_cannot_create_spinoff(): void
    {
        [$owner, $group, $token] = $this->makeGroupWithOwner('arens-holding');

        $outsider = User::factory()->create(['password' => 'secret123']);
        $outsiderTenant = Tenant::create(['name' => 'Other Co', 'slug' => 'other-co', 'status' => 'active']);
        $outsider->tenants()->attach($outsiderTenant, ['status' => 'active']);

        $outsiderToken = $this->createTokenFor($outsider, $outsiderTenant);

        $this->withHeader('Authorization', 'Bearer '.$outsiderToken)
            ->postJson("/api/groups/{$group->slug}/tenants", [
                'name' => 'Spy Spinoff',
                'slug' => 'spy-spinoff',
                'admin' => ['name' => 'X', 'email' => 'x@y.test'],
            ])
            ->assertForbidden();
    }

    public function test_cannot_create_spinoff_in_a_tenant_that_is_not_a_group(): void
    {
        $realGroup = Tenant::create([
            'name' => 'Real Group',
            'slug' => 'real-group',
            'status' => 'active',
        ]);

        $spinoff = Tenant::create([
            'name' => 'Already a spinoff',
            'slug' => 'already-spinoff',
            'status' => 'active',
            'parent_id' => $realGroup->id,
        ]);

        $owner = User::factory()->create();
        $owner->tenants()->attach($realGroup, ['status' => 'active']);

        $token = $this->createTokenFor($owner, $realGroup);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/groups/{$spinoff->slug}/tenants", [
                'name' => 'Should Fail',
                'slug' => 'should-fail',
                'admin' => ['name' => 'A', 'email' => 'a@b.test'],
            ])
            ->assertStatus(404);
    }

    public function test_duplicate_slug_rejected_in_spinoff(): void
    {
        [$owner, $group, $token] = $this->makeGroupWithOwner('arens-holding');

        Tenant::create([
            'name' => 'Existing Spinoff',
            'slug' => 'existing-spinoff',
            'status' => 'active',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/groups/{$group->slug}/tenants", [
                'name' => 'New',
                'slug' => 'existing-spinoff',
                'admin' => ['name' => 'A', 'email' => 'a@b.test'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_group_owner_can_list_their_spinoffs(): void
    {
        [$owner, $group, $token] = $this->makeGroupWithOwner('arens-holding');

        Tenant::create([
            'name' => 'Spinoff One',
            'slug' => 'spinoff-one',
            'status' => 'active',
            'parent_id' => $group->id,
        ]);
        Tenant::create([
            'name' => 'Spinoff Two',
            'slug' => 'spinoff-two',
            'status' => 'active',
            'parent_id' => $group->id,
        ]);
        Tenant::create([
            'name' => 'Other Group',
            'slug' => 'other-group',
            'status' => 'active',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/groups/{$group->slug}/tenants")
            ->assertOk()
            ->json();

        $slugs = array_column($response['data'], 'slug');
        $this->assertContains('spinoff-one', $slugs);
        $this->assertContains('spinoff-two', $slugs);
        $this->assertNotContains('other-group', $slugs);
    }

    public function test_audit_log_records_spinoff_creation(): void
    {
        [$owner, $group, $token] = $this->makeGroupWithOwner('arens-holding');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/groups/{$group->slug}/tenants", [
                'name' => 'Audited Spinoff',
                'slug' => 'audited-spinoff',
                'admin' => ['name' => 'A', 'email' => 'a@b.test'],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant.spun_off_from_group',
        ]);
    }

    /**
     * @return array{0: User, 1: Tenant, 2: string}
     */
    private function makeGroupWithOwner(string $slug): array
    {
        $group = Tenant::create([
            'name' => 'Test Group',
            'slug' => $slug,
            'status' => 'active',
            'plan' => 'enterprise',
        ]);

        $owner = User::factory()->create(['password' => 'secret123']);
        $owner->tenants()->attach($group, ['status' => 'active']);

        setPermissionsTeamId($group->id);
        $role = \Spatie\Permission\Models\Role::create([
            'name' => 'Owner-'.uniqid(),
            'guard_name' => 'web',
            config('permission.column_names.team_foreign_key', 'team_id') => $group->id,
        ]);
        $permissions = Permission::query()->whereIn('name', \App\Support\Permissions\BasePermissions::PERMISSIONS)->get();
        $role->syncPermissions($permissions);
        $owner->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $token = $this->createTokenFor($owner, $group);

        return [$owner, $group, $token];
    }

    private function createTokenFor(User $user, ?Tenant $tenant): string
    {
        $plainToken = Str::random(80);
        AuthToken::create([
            'tenant_id' => $tenant?->id,
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