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

    public function test_group_owner_can_create_and_redeem_a_bundle_for_all_group_tenants(): void
    {
        [$group, $owner] = $this->tenantUser('grupo-bundle', 'owner@bundle.test');
        [$childOne, $childUserOne] = $this->tenantUser('empresa-bundle-uno', 'uno@bundle.test');
        [$childTwo, $childUserTwo] = $this->tenantUser('empresa-bundle-dos', 'dos@bundle.test');
        $childOne->update(['parent_id' => $group->id, 'is_group' => false]);
        $childTwo->update(['parent_id' => $group->id, 'is_group' => false]);

        $owner->tenants()->syncWithoutDetaching([
            $childOne->id => ['status' => 'active'],
            $childTwo->id => ['status' => 'active'],
        ]);
        $this->grantPermission($group, $owner, 'sync.issue_token');

        $response = $this->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->postJson('/api/sync/group-pairing-codes', [
                'user_email' => $owner->email,
                'node_name' => 'Grupo Bundle PC',
                'expires_in_minutes' => 15,
            ])
            ->assertCreated()
            ->assertJsonPath('data.group.slug', $group->slug);

        $code = $response->json('data.code');
        $this->assertSame(40, strlen($code));
        $this->assertSame(
            [$group->slug, $childOne->slug, $childTwo->slug],
            collect($response->json('data.tenants'))->pluck('slug')->all(),
        );

        $redeemed = $this->postJson('/api/sync/pairing-codes/redeem', [
            'code' => $code,
            'node_code' => 'GROUP-BUNDLE-01',
            'node_name' => 'Grupo Bundle PC',
        ])->assertCreated();

        $tokens = collect($redeemed->json('data.tenants'));
        $this->assertSame(
            [$group->slug, $childOne->slug, $childTwo->slug],
            $tokens->pluck('tenant.slug')->all(),
        );
        $this->assertCount(3, $tokens->pluck('token')->filter()->unique());
        $this->assertDatabaseHas('sync_pairing_codes', [
            'code_hash' => hash('sha256', $code),
            'is_group_bundle' => true,
        ]);

        $this->postJson('/api/sync/pairing-codes/redeem', [
            'code' => $code,
            'node_code' => 'GROUP-BUNDLE-02',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);

        unset($childUserOne, $childUserTwo);
    }

    public function test_group_bundle_can_redeem_only_selected_group_tenants(): void
    {
        [$group, $owner] = $this->tenantUser('grupo-selectivo', 'owner@selectivo.test');
        [$childOne, $childUserOne] = $this->tenantUser('empresa-selectiva-uno', 'uno@selectivo.test');
        [$childTwo, $childUserTwo] = $this->tenantUser('empresa-selectiva-dos', 'dos@selectivo.test');
        $childOne->update(['parent_id' => $group->id, 'is_group' => false]);
        $childTwo->update(['parent_id' => $group->id, 'is_group' => false]);
        $owner->tenants()->syncWithoutDetaching([
            $childOne->id => ['status' => 'active'],
            $childTwo->id => ['status' => 'active'],
        ]);
        $this->grantPermission($group, $owner, 'sync.issue_token');

        $code = $this->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->postJson('/api/sync/group-pairing-codes', [
                'user_email' => $owner->email,
                'node_name' => 'Grupo selectivo',
            ])
            ->assertCreated()
            ->json('data.code');

        $redeemed = $this->postJson('/api/sync/pairing-codes/redeem', [
            'code' => $code,
            'node_code' => 'SELECTIVO-01',
            'selected_tenant_ids' => [$childTwo->id],
        ])->assertCreated();

        $this->assertSame(
            [$childTwo->slug],
            collect($redeemed->json('data.tenants'))->pluck('tenant.slug')->all(),
        );
        $this->assertDatabaseHas('auth_tokens', ['tenant_id' => $childTwo->id]);
        $this->assertDatabaseMissing('auth_tokens', ['tenant_id' => $childOne->id]);

        unset($childUserOne, $childUserTwo);
    }

    public function test_group_bundle_rejects_a_selected_tenant_outside_the_group(): void
    {
        [$group, $owner] = $this->tenantUser('grupo-selectivo-seguro', 'owner@selectivo-seguro.test');
        [$child, $childUser] = $this->tenantUser('empresa-selectiva-segura', 'child@selectivo-seguro.test');
        [$other, $otherUser] = $this->tenantUser('empresa-fuera-selectiva', 'other@selectivo-seguro.test');
        $child->update(['parent_id' => $group->id, 'is_group' => false]);
        $owner->tenants()->syncWithoutDetaching([$child->id => ['status' => 'active']]);
        $this->grantPermission($group, $owner, 'sync.issue_token');

        $code = $this->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->postJson('/api/sync/group-pairing-codes', [
                'user_email' => $owner->email,
                'node_name' => 'Grupo seguro',
            ])
            ->assertCreated()
            ->json('data.code');

        $this->postJson('/api/sync/pairing-codes/redeem', [
            'code' => $code,
            'node_code' => 'SEGURO-01',
            'selected_tenant_ids' => [$other->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['selected_tenant_ids']);

        $this->assertDatabaseCount('auth_tokens', 0);
        unset($childUser, $otherUser);
    }

    public function test_group_pairing_preview_does_not_consume_the_code(): void
    {
        [$group, $owner] = $this->tenantUser('grupo-preview', 'owner@preview.test');
        [$child, $childUser] = $this->tenantUser('empresa-preview', 'child@preview.test');
        $child->update(['parent_id' => $group->id, 'is_group' => false]);
        $owner->tenants()->syncWithoutDetaching([$child->id => ['status' => 'active']]);
        $this->grantPermission($group, $owner, 'sync.issue_token');

        $code = $this->actingAs($owner)
            ->withHeader('X-Tenant', $group->slug)
            ->postJson('/api/sync/group-pairing-codes', [
                'user_email' => $owner->email,
                'node_name' => 'Preview',
            ])
            ->assertCreated()
            ->json('data.code');

        $this->postJson('/api/sync/pairing-codes/preview', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.group.slug', $group->slug)
            ->assertJsonPath('data.tenants.1.slug', $child->slug);

        $this->assertDatabaseHas('sync_pairing_codes', [
            'code_hash' => hash('sha256', $code),
            'redeemed_at' => null,
        ]);
        unset($childUser);
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
