<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SyncTokenApiTest extends TestCase
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

    public function test_manager_can_issue_sync_token_from_api(): void
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Token API',
            'slug' => 'empresa-token-api',
        ]);
        $user = User::factory()->create([
            'email' => 'gerente-token@example.test',
        ]);
        $user->tenants()->attach($tenant->id, ['status' => 'active']);

        app(\App\Support\Tenancy\TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $role = Role::findOrCreate('Gerente Token', 'web');
        $role->syncPermissions(['sync.issue_token']);
        $user->assignRole($role);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/tokens', [
                'name' => 'Instalacion Valencia Norte',
                'days' => 90,
            ])
            ->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.name', 'Instalacion Valencia Norte')
            ->assertJsonPath('data.tenant.slug', $tenant->slug);

        $plainToken = $response->json('data.token');

        $this->assertIsString($plainToken);
        $this->assertSame(80, strlen($plainToken));
        $this->assertDatabaseHas('auth_tokens', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Instalacion Valencia Norte',
            'token_hash' => hash('sha256', $plainToken),
        ]);
        $this->assertDatabaseMissing('auth_tokens', [
            'token_hash' => $plainToken,
        ]);
    }

    public function test_sync_token_api_requires_authentication(): void
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Token Protegida',
            'slug' => 'empresa-token-protegida',
        ]);

        $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sync/tokens', [
                'name' => 'Sin login',
            ])
            ->assertUnauthorized();
    }
}
