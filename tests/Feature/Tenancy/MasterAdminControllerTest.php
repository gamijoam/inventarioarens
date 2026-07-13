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

class MasterAdminControllerTest extends TestCase
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

    public function test_stats_returns_platform_totals(): void
    {
        $g1 = Tenant::create(['name' => 'G1', 'slug' => 'g1', 'status' => 'active']);
        $g2 = Tenant::create(['name' => 'G2', 'slug' => 'g2', 'status' => 'inactive', 'parent_id' => $g1->id]);
        User::factory()->create(['is_platform_admin' => true]);

        $token = $this->makePlatformAdminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/master/stats')
            ->assertOk()
            ->json();

        $this->assertGreaterThanOrEqual(2, $response['data']['totals']['total_tenants']);
        $this->assertGreaterThanOrEqual(1, $response['data']['totals']['total_groups']);
        $this->assertGreaterThanOrEqual(1, $response['data']['totals']['total_spinoffs']);
        $this->assertGreaterThanOrEqual(1, $response['data']['totals']['active_tenants']);
        $this->assertGreaterThanOrEqual(1, $response['data']['totals']['inactive_tenants']);
        $this->assertGreaterThanOrEqual(2, $response['data']['totals']['platform_admins']);
    }

    public function test_show_group_returns_group_with_counts(): void
    {
        $group = Tenant::create(['name' => 'Grupo Test', 'slug' => 'grupo-test', 'status' => 'active']);
        Tenant::create(['name' => 'Spinoff1', 'slug' => 'spinoff1', 'status' => 'active', 'parent_id' => $group->id]);
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $admin->tenants()->attach($group, ['status' => 'active']);

        $token = $this->makePlatformAdminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/master/groups/{$group->id}")
            ->assertOk()
            ->json();

        $this->assertSame('grupo-test', $response['data']['slug']);
        $this->assertTrue($response['data']['is_group']);
        $this->assertSame(1, $response['data']['spinoffs_count']);
        $this->assertSame(1, $response['data']['users_count']);
    }

    public function test_show_group_404_for_spinoff(): void
    {
        $spinoff = Tenant::create(['name' => 'X', 'slug' => 'x', 'status' => 'active', 'parent_id' => null]);
        $realGroup = Tenant::create(['name' => 'G', 'slug' => 'g', 'status' => 'active', 'parent_id' => null]);
        $spinoff->update(['parent_id' => $realGroup->id]);

        $token = $this->makePlatformAdminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/master/groups/{$spinoff->id}")
            ->assertStatus(404);
    }

    public function test_update_group_changes_name_plan_status(): void
    {
        $group = Tenant::create(['name' => 'Antes', 'slug' => 'antes', 'status' => 'active', 'plan' => 'demo']);
        $token = $this->makePlatformAdminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/master/groups/{$group->id}", [
                'name' => 'Despues',
                'plan' => 'premium',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Despues')
            ->assertJsonPath('data.plan', 'premium');

        $this->assertDatabaseHas('tenants', [
            'id' => $group->id,
            'name' => 'Despues',
            'plan' => 'premium',
        ]);
    }

    public function test_destroy_group_soft_deletes(): void
    {
        $group = Tenant::create(['name' => 'X', 'slug' => 'x', 'status' => 'active']);
        $token = $this->makePlatformAdminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/master/groups/{$group->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('tenants', ['id' => $group->id, 'status' => 'inactive']);
    }

    public function test_list_group_spinoffs(): void
    {
        $group = Tenant::create(['name' => 'G', 'slug' => 'g', 'status' => 'active']);
        Tenant::create(['name' => 'S1', 'slug' => 's1', 'status' => 'active', 'parent_id' => $group->id]);
        Tenant::create(['name' => 'S2', 'slug' => 's2', 'status' => 'active', 'parent_id' => $group->id]);
        Tenant::create(['name' => 'Otro', 'slug' => 'otro', 'status' => 'active']);

        $token = $this->makePlatformAdminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/master/groups/{$group->id}/tenants")
            ->assertOk()
            ->json();

        $slugs = array_column($response['data'], 'slug');
        $this->assertContains('s1', $slugs);
        $this->assertContains('s2', $slugs);
        $this->assertNotContains('otro', $slugs);
    }

    public function test_show_admin_returns_admin_resource(): void
    {
        $admin = User::factory()->create([
            'name' => 'Boss',
            'email' => 'boss@platform.test',
            'is_platform_admin' => true,
        ]);

        $token = $this->makePlatformAdminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/master/admins/{$admin->id}")
            ->assertOk()
            ->assertJsonPath('data.email', 'boss@platform.test')
            ->assertJsonPath('data.is_platform_admin', true);
    }

    public function test_show_admin_404_for_non_platform_admin(): void
    {
        $user = User::factory()->create(['is_platform_admin' => false]);
        $token = $this->makePlatformAdminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/master/admins/{$user->id}")
            ->assertStatus(404);
    }

    public function test_update_admin_changes_name_and_email(): void
    {
        $admin = User::factory()->create([
            'name' => 'Original',
            'email' => 'orig@platform.test',
            'is_platform_admin' => true,
        ]);
        $token = $this->makePlatformAdminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/master/admins/{$admin->id}", [
                'name' => 'Modificado',
                'email' => 'mod@platform.test',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Modificado')
            ->assertJsonPath('data.email', 'mod@platform.test');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'name' => 'Modificado', 'email' => 'mod@platform.test']);
    }

    public function test_update_admin_can_demote_to_non_platform(): void
    {
        $admin = User::factory()->create([
            'name' => 'Boss',
            'email' => 'boss@platform.test',
            'is_platform_admin' => true,
        ]);
        $token = $this->makePlatformAdminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/master/admins/{$admin->id}", [
                'is_platform_admin' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_platform_admin', false);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_platform_admin' => false]);
    }

    public function test_reset_password_returns_generated_password_and_revokes_sessions(): void
    {
        $admin = User::factory()->create([
            'name' => 'Boss',
            'email' => 'boss@platform.test',
            'is_platform_admin' => true,
        ]);
        AuthToken::create([
            'tenant_id' => null,
            'user_id' => $admin->id,
            'name' => 'old',
            'token_hash' => hash('sha256', 'old'),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->makePlatformAdminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/master/admins/{$admin->id}/reset-password")
            ->assertOk()
            ->json();

        $this->assertNotEmpty($response['data']['initial_password']);
        $this->assertTrue($response['data']['sessions_revoked']);
        $this->assertDatabaseHas('auth_tokens', [
            'user_id' => $admin->id,
            'name' => 'old',
        ]);
        $this->assertNotNull(DB::table('auth_tokens')->where('user_id', $admin->id)->where('name', 'old')->value('revoked_at'));
    }

    public function test_destroy_admin_revokes_is_platform_admin(): void
    {
        $target = User::factory()->create([
            'name' => 'Target',
            'email' => 'target@platform.test',
            'is_platform_admin' => true,
        ]);
        $token = $this->makePlatformAdminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/master/admins/{$target->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_platform_admin' => false]);
    }

    public function test_destroy_admin_cannot_self_revoke(): void
    {
        $admin = User::factory()->create([
            'name' => 'Self',
            'email' => 'self@platform.test',
            'is_platform_admin' => true,
        ]);
        $token = $this->makeTokenFor($admin);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/master/admins/{$admin->id}")
            ->assertStatus(422);
    }

    public function test_audit_log_records_platform_admin_upserted(): void
    {
        $token = $this->makePlatformAdminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/master/admins', [
                'name' => 'New Admin',
                'email' => 'new@platform.test',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_admin.upserted']);
    }

    public function test_audit_log_records_group_deactivated(): void
    {
        $group = Tenant::create(['name' => 'G', 'slug' => 'g', 'status' => 'active']);
        $token = $this->makePlatformAdminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/master/groups/{$group->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('audit_logs', ['action' => 'tenant_group.deactivated']);
    }

    public function test_non_platform_admin_cannot_access_any_master_route(): void
    {
        $user = User::factory()->create(['is_platform_admin' => false]);
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $token = $this->makeTokenFor($user);

        foreach (['/api/master/stats', '/api/master/groups', '/api/master/admins'] as $url) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson($url)
                ->assertForbidden();
        }
    }

    private function makePlatformAdminToken(): string
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        return $this->makeTokenFor($admin);
    }

    private function makeTokenFor(User $user): string
    {
        $plainToken = Str::random(80);
        AuthToken::create([
            'tenant_id' => $user->is_platform_admin ? null : null,
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