<?php

namespace Tests\Feature\Bootstrap;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BootstrapApiTest extends TestCase
{
    use RefreshDatabase;

    private const BOOTSTRAP_TOKEN = 'test-bootstrap-token-do-not-use-in-prod';

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('127.0.0.1');
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('127.0.0.1');

        parent::tearDown();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'SaaS Master',
            'email' => 'admin@saas.test',
            'password' => 'SecretPassword123!',
            'bootstrap_token' => self::BOOTSTRAP_TOKEN,
        ], $overrides);
    }

    public function test_creates_platform_admin_when_database_is_empty(): void
    {
        $response = $this->postJson('/api/bootstrap', $this->validPayload());

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email', 'is_platform_admin'],
                    'tenant',
                    'token',
                    'token_type',
                    'expires_at',
                    'initial_password',
                    'next_steps',
                ],
            ])
            ->assertJsonPath('data.user.email', 'admin@saas.test')
            ->assertJsonPath('data.user.is_platform_admin', true)
            ->assertJsonPath('data.tenant', null)
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertDatabaseHas('users', [
            'email' => 'admin@saas.test',
            'is_platform_admin' => true,
        ]);

        $this->assertNull(
            User::where('email', 'admin@saas.test')->firstOrFail()->password
                ? null
                : null,
        );

        $this->assertDatabaseCount('auth_tokens', 1);
    }

    public function test_returned_token_works_for_master_endpoints(): void
    {
        $login = $this->postJson('/api/bootstrap', $this->validPayload())->assertCreated();
        $token = $login->json('data.token');

        $this->withToken($token)
            ->getJson('/api/master/admins')
            ->assertOk()
            ->assertJsonFragment(['email' => 'admin@saas.test']);
    }

    public function test_returned_token_works_for_platform_login_round_trip(): void
    {
        $payload = $this->validPayload();
        $login = $this->postJson('/api/bootstrap', $payload)->assertCreated();

        $token = $login->json('data.token');

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.is_platform_admin', true)
            ->assertJsonPath('data.tenant', null);
    }

    public function test_returns_generated_password_when_not_provided(): void
    {
        $payload = $this->validPayload();
        unset($payload['password']);

        $response = $this->postJson('/api/bootstrap', $payload)->assertCreated();

        $generated = $response->json('data.initial_password');
        $this->assertIsString($generated);
        $this->assertGreaterThanOrEqual(16, strlen($generated));

        $user = User::where('email', 'admin@saas.test')->firstOrFail();
        $this->assertTrue(Hash::check($generated, $user->password));
    }

    public function test_omits_initial_password_when_provided(): void
    {
        $response = $this->postJson('/api/bootstrap', $this->validPayload())
            ->assertCreated();

        $this->assertNull($response->json('data.initial_password'));
    }

    public function test_creates_initial_tenant_and_assigns_administrador_role(): void
    {
        $payload = $this->validPayload([
            'tenant' => [
                'name' => 'Demo Empresa',
                'slug' => 'demo-empresa',
                'plan' => 'standard',
            ],
        ]);

        $response = $this->postJson('/api/bootstrap', $payload)
            ->assertCreated()
            ->assertJsonPath('data.tenant.slug', 'demo-empresa')
            ->assertJsonPath('data.tenant.status', 'active')
            ->assertJsonPath('data.tenant.plan', 'standard');

        $this->assertDatabaseHas('tenants', [
            'slug' => 'demo-empresa',
            'status' => 'active',
            'plan' => 'standard',
        ]);

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => Tenant::where('slug', 'demo-empresa')->value('id'),
            'user_id' => User::where('email', 'admin@saas.test')->value('id'),
            'status' => 'active',
        ]);

        $user = User::where('email', 'admin@saas.test')->firstOrFail();
        $tenant = Tenant::where('slug', 'demo-empresa')->firstOrFail();

        $this->assertTrue($user->belongsToTenant($tenant));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($tenant->id);

        $this->assertTrue($user->hasRole('Administrador'));
        $permissionCount = $user->getAllPermissions()->count();
        $this->assertSame(count(BasePermissions::PERMISSIONS), $permissionCount);

        $this->assertDatabaseHas('roles', ['name' => 'Administrador', 'guard_name' => 'web']);
        $this->assertDatabaseHas('roles', ['name' => 'Owner', 'guard_name' => 'web']);
        $this->assertDatabaseHas('roles', ['name' => 'Gerente', 'guard_name' => 'web']);
        $this->assertDatabaseHas('roles', ['name' => 'Vendedor', 'guard_name' => 'web']);
        $this->assertDatabaseHas('roles', ['name' => 'Almacen', 'guard_name' => 'web']);
        $this->assertDatabaseHas('roles', ['name' => 'Auditor', 'guard_name' => 'web']);
    }

    public function test_rejects_when_database_is_not_empty(): void
    {
        User::create([
            'name' => 'Existing',
            'email' => 'existing@example.test',
            'password' => Hash::make('password'),
            'is_platform_admin' => false,
        ]);

        $response = $this->postJson('/api/bootstrap', $this->validPayload());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['bootstrap']);
    }

    public function test_rejects_when_tenant_already_exists(): void
    {
        Tenant::create(['name' => 'Pre-existente', 'slug' => 'ya-existe']);

        $response = $this->postJson('/api/bootstrap', $this->validPayload());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['bootstrap']);
    }

    public function test_rejects_when_token_is_wrong(): void
    {
        $response = $this->postJson('/api/bootstrap', $this->validPayload([
            'bootstrap_token' => 'wrong-token',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['bootstrap_token']);
    }

    public function test_rejects_when_token_is_missing(): void
    {
        $payload = $this->validPayload();
        unset($payload['bootstrap_token']);

        $response = $this->postJson('/api/bootstrap', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['bootstrap_token']);
    }

    public function test_accepts_token_via_header(): void
    {
        $payload = $this->validPayload();
        unset($payload['bootstrap_token']);

        $response = $this->withHeader('X-Bootstrap-Token', self::BOOTSTRAP_TOKEN)
            ->postJson('/api/bootstrap', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'admin@saas.test');
    }

    public function test_rejects_invalid_payload(): void
    {
        $response = $this->postJson('/api/bootstrap', [
            'name' => '',
            'email' => 'not-an-email',
            'bootstrap_token' => self::BOOTSTRAP_TOKEN,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_rejects_invalid_tenant_slug(): void
    {
        $response = $this->postJson('/api/bootstrap', $this->validPayload([
            'tenant' => [
                'name' => 'X',
                'slug' => 'Invalid Slug With Spaces',
            ],
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tenant.slug']);
    }

    public function test_rejects_when_bootstrap_disabled(): void
    {
        $previous = $_ENV['APP_BOOTSTRAP_TOKEN'] ?? null;
        putenv('APP_BOOTSTRAP_TOKEN=');
        $_ENV['APP_BOOTSTRAP_TOKEN'] = '';

        try {
            $response = $this->postJson('/api/bootstrap', $this->validPayload());

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['bootstrap']);
        } finally {
            if ($previous !== null) {
                putenv("APP_BOOTSTRAP_TOKEN={$previous}");
                $_ENV['APP_BOOTSTRAP_TOKEN'] = $previous;
            }
        }
    }

    public function test_throttle_limits_requests_per_ip(): void
    {
        $payload = $this->validPayload();

        $this->postJson('/api/bootstrap', $payload)->assertCreated();
        $this->postJson('/api/bootstrap', $payload)->assertStatus(422);
        $this->postJson('/api/bootstrap', $payload)->assertStatus(422);
        $this->postJson('/api/bootstrap', $payload)->assertStatus(429);
    }

    public function test_writes_audit_log_for_bootstrap_completed(): void
    {
        $this->postJson('/api/bootstrap', $this->validPayload())->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'bootstrap.completed',
        ]);
    }

    public function test_writes_audit_log_for_bootstrap_rejected(): void
    {
        $this->postJson('/api/bootstrap', $this->validPayload([
            'bootstrap_token' => 'wrong',
        ]))->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'bootstrap.rejected',
        ]);
    }
}
