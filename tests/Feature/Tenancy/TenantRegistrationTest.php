<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantRegistrationTest extends TestCase
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

    public function test_register_creates_tenant_with_admin_branch_warehouse_and_rate_type(): void
    {
        $actor = $this->makeActorWithToken(['tenants.create']);

        $payload = [
            'name' => 'Demo Valencia Sur',
            'slug' => 'demo-valencia-sur',
            'plan' => 'demo',
            'admin' => [
                'name' => 'Owner Valencia Sur',
                'email' => 'owner.valencia.sur@demo.test',
                'password' => 'Secret123',
            ],
            'branch' => [
                'name' => 'Principal Sur',
                'code' => 'SUR',
            ],
            'warehouse' => [
                'name' => 'Almacen Sur',
                'code' => 'SUR-01',
            ],
            'exchange_rate_type' => [
                'code' => 'BCV',
                'name' => 'Banco Central de Venezuela',
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->postJson('/api/tenants', $payload)
            ->assertCreated()
            ->json();

        $this->assertSame('demo-valencia-sur', $response['data']['slug']);
        $this->assertSame('active', $response['data']['status']);

        $this->assertDatabaseHas('tenants', ['slug' => 'demo-valencia-sur']);

        $this->assertDatabaseHas('users', ['email' => 'owner.valencia.sur@demo.test']);

        $tenant = Tenant::where('slug', 'demo-valencia-sur')->first();
        $this->assertDatabaseHas('branches', ['tenant_id' => $tenant->id, 'code' => 'SUR']);
        $branch = Branch::where('tenant_id', $tenant->id)->where('code', 'SUR')->first();
        $this->assertDatabaseHas('warehouses', ['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'code' => 'SUR-01']);
        $this->assertDatabaseHas('exchange_rate_types', ['tenant_id' => $tenant->id, 'code' => 'BCV', 'is_default' => true]);
    }

    public function test_register_admin_is_assigned_administrador_role_with_all_permissions(): void
    {
        $actor = $this->makeActorWithToken(['tenants.create']);

        $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->postJson('/api/tenants', [
                'name' => 'Empresa Permisos',
                'slug' => 'empresa-permisos',
                'admin' => ['name' => 'Admin', 'email' => 'admin@permisos.test', 'password' => 'Secret123'],
            ])
            ->assertCreated();

        $tenant = Tenant::where('slug', 'empresa-permisos')->first();
        $admin = User::where('email', 'admin@permisos.test')->first();

        setPermissionsTeamId($tenant->id);
        $this->assertTrue($admin->hasRole('Administrador'));

        $adminRole = Role::where('name', 'Administrador')
            ->where(config('permission.column_names.team_foreign_key', 'team_id'), $tenant->id)
            ->first();

        $this->assertNotNull($adminRole);
        $this->assertGreaterThan(80, $adminRole->permissions->count());
    }

    public function test_register_admin_can_immediately_login_into_new_tenant(): void
    {
        $actor = $this->makeActorWithToken(['tenants.create']);

        $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->postJson('/api/tenants', [
                'name' => 'Empresa Login',
                'slug' => 'empresa-login',
                'admin' => ['name' => 'Admin', 'email' => 'login@new.test', 'password' => 'Secret123'],
            ])
            ->assertCreated();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $tenant = Tenant::where('slug', 'empresa-login')->first();
        $admin = User::where('email', 'login@new.test')->first();

        $this->assertTrue(
            $admin->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists(),
            'El admin debe estar activo en empresa-login'
        );

        $this->app->forgetInstance('auth');
        auth()->forgetGuards();

        $response = $this->withHeader('X-Tenant', 'empresa-login')
            ->withHeader('Authorization', '')
            ->postJson('/api/auth/login', [
                'email' => 'login@new.test',
                'password' => 'Secret123',
                'device_name' => 'test',
            ]);

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertNotEmpty($data['token']);
        $this->assertSame('empresa-login', $data['tenant']['slug']);
        $this->assertSame('Administrador', $data['roles'][0]);
    }

    public function test_register_with_minimal_payload_works(): void
    {
        $actor = $this->makeActorWithToken(['tenants.create']);

        $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->postJson('/api/tenants', [
                'name' => 'Empresa Minima',
                'slug' => 'empresa-minima',
                'admin' => ['name' => 'Admin', 'email' => 'min@ima.test'],
            ])
            ->assertCreated();

        $tenant = Tenant::where('slug', 'empresa-minima')->first();
        $this->assertSame(0, $tenant->branches()->count(), 'Sin branch en payload no debe crear ninguno');
        $this->assertSame(0, $tenant->warehouses()->count());
        $this->assertSame(1, $tenant->users()->count(), 'Solo el admin');
    }

    public function test_register_with_branch_without_warehouse_is_valid(): void
    {
        $actor = $this->makeActorWithToken(['tenants.create']);

        $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->postJson('/api/tenants', [
                'name' => 'Solo Branch',
                'slug' => 'solo-branch',
                'admin' => ['name' => 'A', 'email' => 'a@b.test'],
                'branch' => ['name' => 'B', 'code' => 'B01'],
            ])
            ->assertCreated();

        $tenant = Tenant::where('slug', 'solo-branch')->first();
        $this->assertSame(1, $tenant->branches()->count());
        $this->assertSame(0, $tenant->warehouses()->count());
    }

    public function test_register_rejects_warehouse_without_branch(): void
    {
        $actor = $this->makeActorWithToken(['tenants.create']);

        $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->postJson('/api/tenants', [
                'name' => 'WH sin branch',
                'slug' => 'wh-sin-branch',
                'admin' => ['name' => 'A', 'email' => 'a@b.test'],
                'warehouse' => ['name' => 'WH', 'code' => 'W01'],
            ])
            ->assertCreated();

        $tenant = Tenant::where('slug', 'wh-sin-branch')->first();
        $this->assertSame(0, $tenant->warehouses()->count(), 'Warehouse debe rechazarse silenciosamente si no hay branch');
    }

    public function test_register_returns_422_on_missing_admin_email(): void
    {
        $actor = $this->makeActorWithToken(['tenants.create']);

        $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->postJson('/api/tenants', [
                'name' => 'Sin admin',
                'slug' => 'sin-admin',
                'admin' => ['name' => 'A'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['admin.email']);
    }

    public function test_register_creates_audit_log_tenant_created(): void
    {
        $actor = $this->makeActorWithToken(['tenants.create']);

        $this->withHeader('Authorization', 'Bearer '.$actor['token'])
            ->postJson('/api/tenants', [
                'name' => 'Audit Test',
                'slug' => 'audit-test',
                'admin' => ['name' => 'A', 'email' => 'audit@t.test'],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant.created',
        ]);
    }

    private function makeActorWithToken(array $permissions): array
    {
        $hostTenant = Tenant::create(['name' => 'Host', 'slug' => 'host', 'status' => 'active']);

        $user = User::factory()->create(['password' => 'secret123']);
        $user->tenants()->attach($hostTenant, ['status' => 'active']);

        setPermissionsTeamId($hostTenant->id);

        $role = Role::create([
            'name' => 'Actor-'.uniqid(),
            'guard_name' => 'web',
            config('permission.column_names.team_foreign_key', 'team_id') => $hostTenant->id,
        ]);
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $plainToken = Str::random(80);
        AuthToken::create([
            'tenant_id' => $hostTenant->id,
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['user' => $user, 'host_tenant' => $hostTenant, 'token' => $plainToken];
    }
}