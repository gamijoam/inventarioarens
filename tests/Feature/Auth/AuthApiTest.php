<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_available_companies_before_login(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        $user = User::factory()->create([
            'email' => 'usuario@example.test',
            'password' => 'secret123',
        ]);
        $user->tenants()->attach($tenantA, ['status' => 'active']);
        $user->tenants()->attach($tenantB, ['status' => 'inactive']);

        $this
            ->postJson('/api/auth/tenants', [
                'email' => 'usuario@example.test',
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'empresa-a');
    }

    public function test_same_email_can_list_multiple_active_companies_before_login(): void
    {
        [$tenantA, $tenantB, $tenantC] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
            Tenant::create(['name' => 'Empresa C', 'slug' => 'empresa-c']),
        ];

        $user = User::factory()->create([
            'email' => 'multiempresa@example.test',
            'password' => 'secret123',
        ]);
        $user->tenants()->attach($tenantA, ['status' => 'active']);
        $user->tenants()->attach($tenantB, ['status' => 'active']);
        $user->tenants()->attach($tenantC, ['status' => 'active']);

        $this
            ->postJson('/api/auth/tenants', [
                'email' => 'multiempresa@example.test',
            ])
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.slug', 'empresa-a')
            ->assertJsonPath('data.1.slug', 'empresa-b')
            ->assertJsonPath('data.2.slug', 'empresa-c');
    }

    public function test_user_can_login_and_receive_tenant_context(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor', ['products.view', 'pos.view']);

        $response = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'secret123',
                'device_name' => 'navegador',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.tenant.slug', $tenant->slug)
            ->assertJsonPath('data.roles.0', 'Vendedor')
            ->assertJsonPath('data.permissions.0', 'pos.view')
            ->assertJsonPath('data.permissions.1', 'products.view')
            ->assertJsonPath('data.token_type', 'Bearer');

        $plainToken = $response->json('data.token');

        $this->assertNotEmpty($plainToken);
        $this->assertDatabaseHas('auth_tokens', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'navegador',
        ]);
        $this->assertDatabaseMissing('auth_tokens', [
            'token_hash' => $plainToken,
        ]);
    }

    public function test_bearer_token_can_access_current_profile_and_protected_apis(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Catalogo', ['products.view']);
        Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Samsung A06',
            'sku' => 'SAM-A06',
        ]);

        $token = $this->loginToken($tenant, $user);

        $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.permissions.0', 'products.view');

        $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'SAM-A06');

        $this->assertNotNull(AuthToken::first()?->last_used_at);
    }

    public function test_token_cannot_be_used_in_another_company(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenantA, ['status' => 'active']);
        $user->tenants()->attach($tenantB, ['status' => 'active']);
        $this->grantRole($tenantA, $user, 'Empresa A', ['products.view']);
        $this->grantRole($tenantB, $user, 'Empresa B', ['products.view']);

        $token = $this->loginToken($tenantA, $user);

        $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson('/api/auth/me')
            ->assertForbidden();
    }

    public function test_authenticated_user_can_switch_to_another_active_company(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenantA, ['status' => 'active']);
        $user->tenants()->attach($tenantB, ['status' => 'active']);
        $this->grantRole($tenantA, $user, 'Empresa A', ['products.view']);
        $this->grantRole($tenantB, $user, 'Empresa B', ['pos.view']);

        $tokenA = $this->loginToken($tenantA, $user);

        $tokenB = $this
            ->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/auth/switch-tenant', [
                'tenant_slug' => $tenantB->slug,
                'device_name' => 'portal-web',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tenant.slug', $tenantB->slug)
            ->assertJsonPath('data.permissions.0', 'pos.view')
            ->json('data.token');

        $this
            ->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', $tenantB->slug);
    }

    public function test_login_rejects_inactive_or_unrelated_company(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = User::factory()->create([
            'email' => 'usuario@example.test',
            'password' => 'secret123',
        ]);
        $user->tenants()->attach($tenant, ['status' => 'inactive']);

        $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => 'usuario@example.test',
                'password' => 'secret123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant']);
    }

    public function test_logout_revokes_current_token(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $token = $this->loginToken($tenant, $user);

        $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_logout_all_revokes_only_tokens_for_current_company(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];
        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($tenantA, ['status' => 'active']);
        $user->tenants()->attach($tenantB, ['status' => 'active']);

        $tokenA = $this->loginToken($tenantA, $user);
        $secondTokenA = $this->loginToken($tenantA, $user);
        $tokenB = $this->loginToken($tenantB, $user);

        $this
            ->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/auth/logout-all')
            ->assertOk()
            ->assertJsonPath('data.revoked_tokens', 2);

        $this
            ->withHeader('Authorization', "Bearer {$secondTokenA}")
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();

        $this
            ->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_protected_routes_require_token_or_authenticated_test_user(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);

        $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/products')
            ->assertUnauthorized();
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create([
            'password' => 'secret123',
        ]);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): Role
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->syncRoles($role);

        return $role;
    }

    private function loginToken(Tenant $tenant, User $user): string
    {
        return $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'secret123',
            ])
            ->assertCreated()
            ->json('data.token');
    }
}
