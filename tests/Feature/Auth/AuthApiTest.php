<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->assertJsonFragment(['capabilities' => ['dashboard', 'catalog', 'inventory', 'customers', 'suppliers', 'sales', 'purchases', 'pos', 'cash_register', 'finance', 'reports', 'promotions', 'commissions', 'warranties', 'workshop', 'intercompany', 'inventory_transfers', 'data_import', 'quotations', 'printing', 'telegram', 'offline_sync']])
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

    public function test_me_endpoint_returns_tenant_and_permissions_for_platform_admin_in_tenant_session(): void
    {
        $tenant = Tenant::create(['name' => 'Repuestos Avilacar', 'slug' => 'repuestos-avilacar']);
        $user = User::factory()->create([
            'email' => 'admin@repuestosavilacar.com',
            'password' => 'secret123',
            'is_platform_admin' => true,
        ]);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $token = $this->loginToken($tenant, $user);

        $response = $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/auth/me')
            ->assertOk();

        $response->assertJsonPath('data.user.id', $user->id);
        $response->assertJsonPath('data.user.is_platform_admin', true);
        $response->assertJsonPath('data.tenant.id', $tenant->id);
        $response->assertJsonPath('data.tenant.slug', $tenant->slug);
    }

    public function test_me_endpoint_returns_null_tenant_for_platform_admin_without_tenant_session(): void
    {
        $user = User::factory()->create([
            'email' => 'superadmin@example.com',
            'password' => 'secret123',
            'is_platform_admin' => true,
        ]);

        $token = $this->postJson('/api/auth/platform-login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])
            ->assertCreated()
            ->json('data.token');

        $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.is_platform_admin', true)
            ->assertJsonPath('data.tenant', null);
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

    public function test_available_tenants_includes_logo_url(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Con Logo', 'slug' => 'empresa-con-logo']);
        $user = User::factory()->create([
            'email' => 'logo@example.test',
            'password' => 'secret123',
        ]);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        DB::table('tenant_settings')->updateOrInsert(
            ['tenant_id' => $tenant->id],
            [
                'settings' => json_encode([
                    'company' => ['logo_url' => '/storage/tenants/' . $tenant->id . '/logo.png'],
                ]),
                'updated_at' => now(),
            ]
        );

        $this
            ->postJson('/api/auth/tenants', [
                'email' => 'logo@example.test',
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.logo_url', '/storage/tenants/' . $tenant->id . '/logo.png');
    }

    public function test_session_includes_tenant_logo_url(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Logo Me', 'slug' => 'empresa-logo-me']);
        $user = User::factory()->create([
            'email' => 'logome@example.test',
            'password' => 'secret123',
        ]);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        DB::table('tenant_settings')->updateOrInsert(
            ['tenant_id' => $tenant->id],
            [
                'settings' => json_encode([
                    'company' => ['logo_url' => '/storage/tenants/' . $tenant->id . '/logo_me.png'],
                ]),
                'updated_at' => now(),
            ]
        );

        $token = $this->loginToken($tenant, $user);

        $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.tenant.logo_url', '/storage/tenants/' . $tenant->id . '/logo_me.png');
    }

    public function test_public_tenant_returns_info_by_domain_or_slug(): void
    {
        $tenant = Tenant::create([
            'name' => 'Avilacar Repuestos',
            'slug' => 'repuestos-avilacar',
            'domain' => 'app.repuestosavilacar.com',
        ]);

        DB::table('tenant_settings')->updateOrInsert(
            ['tenant_id' => $tenant->id],
            [
                'settings' => json_encode([
                    'company' => [
                        'razon_social' => 'Repuestos Avilacar, C.A.',
                        'logo_url' => '/storage/tenants/' . $tenant->id . '/logo_avilacar.png',
                    ],
                ]),
                'updated_at' => now(),
            ]
        );

        $this
            ->getJson('http://app.repuestosavilacar.com/api/auth/public-tenant')
            ->assertOk()
            ->assertJsonPath('data.slug', 'repuestos-avilacar')
            ->assertJsonPath('data.name', 'Repuestos Avilacar, C.A.')
            ->assertJsonPath('data.logo_url', '/storage/tenants/' . $tenant->id . '/logo_avilacar.png');
    }

    public function test_user_with_single_company_logs_in_directly_without_x_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Única', 'slug' => 'empresa-unica']);
        $user = User::factory()->create([
            'email' => 'unica@example.test',
            'password' => 'secret123',
        ]);
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $user, 'Administrador', ['products.view']);

        $response = $this
            ->postJson('/api/auth/login', [
                'email' => 'unica@example.test',
                'password' => 'secret123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.requires_tenant_selection', false)
            ->assertJsonPath('data.tenant.slug', 'empresa-unica')
            ->assertJsonPath('data.user.email', 'unica@example.test')
            ->assertJsonPath('data.token_type', 'Bearer');

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('auth_tokens', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_user_with_multiple_companies_receives_selection_payload_without_x_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Alfa Corp', 'slug' => 'alfa-corp']);
        $tenantB = Tenant::create(['name' => 'Beta Corp', 'slug' => 'beta-corp']);
        $user = User::factory()->create([
            'email' => 'multi@example.test',
            'password' => 'secret123',
        ]);
        $user->tenants()->attach($tenantA, ['status' => 'active']);
        $user->tenants()->attach($tenantB, ['status' => 'active']);

        $response = $this
            ->postJson('/api/auth/login', [
                'email' => 'multi@example.test',
                'password' => 'secret123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.requires_tenant_selection', true)
            ->assertJsonPath('data.user.email', 'multi@example.test')
            ->assertJsonPath('data.tenant', null)
            ->assertJsonCount(2, 'data.tenants')
            ->assertJsonPath('data.tenants.0.slug', 'alfa-corp')
            ->assertJsonPath('data.tenants.1.slug', 'beta-corp');

        $interimToken = $response->json('data.token');
        $this->assertNotEmpty($interimToken);

        $this->assertDatabaseHas('auth_tokens', [
            'tenant_id' => null,
            'user_id' => $user->id,
        ]);
    }

    public function test_user_with_interim_token_can_switch_tenant_to_complete_login(): void
    {
        $tenantA = Tenant::create(['name' => 'Alfa Corp', 'slug' => 'alfa-corp']);
        $tenantB = Tenant::create(['name' => 'Beta Corp', 'slug' => 'beta-corp']);
        $user = User::factory()->create([
            'email' => 'multi_switch@example.test',
            'password' => 'secret123',
        ]);
        $user->tenants()->attach($tenantA, ['status' => 'active']);
        $user->tenants()->attach($tenantB, ['status' => 'active']);
        $this->grantRole($tenantB, $user, 'Vendedor', ['pos.view']);

        $loginResponse = $this
            ->postJson('/api/auth/login', [
                'email' => 'multi_switch@example.test',
                'password' => 'secret123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.requires_tenant_selection', true);

        $interimToken = $loginResponse->json('data.token');

        $switchResponse = $this
            ->withHeader('Authorization', "Bearer {$interimToken}")
            ->postJson('/api/auth/switch-tenant', [
                'tenant_slug' => 'beta-corp',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tenant.slug', 'beta-corp')
            ->assertJsonPath('data.user.email', 'multi_switch@example.test')
            ->assertJsonPath('data.permissions.0', 'pos.view');

        $finalToken = $switchResponse->json('data.token');
        $this->assertNotEmpty($finalToken);
        $this->assertDatabaseHas('auth_tokens', [
            'tenant_id' => $tenantB->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_user_with_zero_active_companies_is_rejected_without_x_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Inactiva', 'slug' => 'empresa-inactiva']);
        $user = User::factory()->create([
            'email' => 'inactivo@example.test',
            'password' => 'secret123',
        ]);
        $user->tenants()->attach($tenant, ['status' => 'inactive']);

        $this
            ->postJson('/api/auth/login', [
                'email' => 'inactivo@example.test',
                'password' => 'secret123',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'El usuario no pertenece a ninguna empresa activa.');
    }

    public function test_legacy_login_with_x_tenant_header_still_works_directly(): void
    {
        $tenantA = Tenant::create(['name' => 'Alfa Corp', 'slug' => 'alfa-corp']);
        $tenantB = Tenant::create(['name' => 'Beta Corp', 'slug' => 'beta-corp']);
        $user = User::factory()->create([
            'email' => 'legacy@example.test',
            'password' => 'secret123',
        ]);
        $user->tenants()->attach($tenantA, ['status' => 'active']);
        $user->tenants()->attach($tenantB, ['status' => 'active']);
        $this->grantRole($tenantB, $user, 'Vendedor', ['pos.view']);

        $this
            ->withHeader('X-Tenant', 'beta-corp')
            ->postJson('/api/auth/login', [
                'email' => 'legacy@example.test',
                'password' => 'secret123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.requires_tenant_selection', false)
            ->assertJsonPath('data.tenant.slug', 'beta-corp')
            ->assertJsonPath('data.user.email', 'legacy@example.test');
    }
}
