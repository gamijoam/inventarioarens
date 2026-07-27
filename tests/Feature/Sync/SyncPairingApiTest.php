<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SyncPairingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_owner_can_create_and_redeem_a_single_use_pairing_code(): void
    {
        [$group, $owner] = $this->tenantUser('grupo-pairing', 'owner@pairing.test');
        [$child, $admin] = $this->tenantUser('empresa-pairing', 'admin@pairing.test');
        $child->update(['parent_id' => $group->id, 'is_group' => false]);
        $this->grantPermission($group, $owner, 'sync.issue_token');

        $response = $this->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->postJson('/api/sync/pairing-codes', [
                'target_tenant_id' => $child->id,
                'user_email' => $admin->email,
                'node_name' => 'POS Caracas',
                'expires_in_minutes' => 15,
            ])
            ->assertCreated()
            ->assertJsonPath('data.tenant.slug', $child->slug);

        $code = $response->json('data.code');
        $this->assertSame(40, strlen($code));
        $this->assertDatabaseHas('sync_pairing_codes', [
            'target_tenant_id' => $child->id,
            'redeemed_at' => null,
        ]);

        $redeemed = $this->postJson('/api/sync/pairing-codes/redeem', [
            'code' => $code,
            'node_code' => 'LOCAL-CARACAS',
        ])
            ->assertCreated()
            ->assertJsonPath('data.tenant.slug', $child->slug);

        $this->assertNotEmpty($redeemed->json('data.token'));
        $this->assertDatabaseHas('auth_tokens', [
            'tenant_id' => $child->id,
            'user_id' => $admin->id,
            'name' => 'POS Caracas',
        ]);

        $this->postJson('/api/sync/pairing-codes/redeem', [
            'code' => $code,
            'node_code' => 'LOCAL-CARACAS-2',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    }

    public function test_owner_cannot_create_a_pairing_code_for_an_unrelated_tenant(): void
    {
        [$group, $owner] = $this->tenantUser('grupo-pairing-2', 'owner2@pairing.test');
        [$other, $admin] = $this->tenantUser('empresa-ajena', 'admin2@pairing.test');
        $this->grantPermission($group, $owner, 'sync.issue_token');

        $this->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->postJson('/api/sync/pairing-codes', [
                'target_tenant_id' => $other->id,
                'user_email' => $admin->email,
                'node_name' => 'No permitido',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('sync_pairing_codes', 0);
    }

    private function tenantUser(string $slug, string $email): array
    {
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug]);
        $user = User::factory()->create(['email' => $email]);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return [$tenant, $user];
    }

    private function grantPermission(Tenant $tenant, User $user, string $permission): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate($permission, 'web');
        $role = Role::findOrCreate('Pairing Owner '.$tenant->slug, 'web');
        $role->syncPermissions([$permission]);
        $user->assignRole($role);
    }
}
