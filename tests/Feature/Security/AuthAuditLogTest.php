<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthAuditLogTest extends TestCase
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

    private function makeTenantAndUser(): array
    {
        $tenant = Tenant::create(['name' => 'Tienda Audit', 'slug' => 'tienda-audit']);
        $user = User::factory()->create(['email' => 'audit@example.test', 'password' => 'correct-password']);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        app(\App\Support\Tenancy\TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $role = Role::findOrCreate('Operador', 'web');
        $role->syncPermissions(['sales.view', 'pos.view']);
        $user->assignRole($role);

        return [$tenant, $user];
    }

    public function test_successful_login_creates_audit_log(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser();

        $countBefore = AuditLog::query()->where('action', 'auth.login.success')->count();

        $this->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => 'audit@example.test',
                'password' => 'correct-password',
            ])
            ->assertCreated();

        $this->assertSame(
            $countBefore + 1,
            AuditLog::query()->where('action', 'auth.login.success')->count(),
            'auth.login.success debe crear un audit log'
        );
    }

    public function test_failed_login_creates_audit_log(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser();

        $countBefore = AuditLog::query()->where('action', 'auth.login.failed')->count();

        $this->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => 'audit@example.test',
                'password' => 'WRONG-password',
            ])
            ->assertUnprocessable();

        $this->assertSame(
            $countBefore + 1,
            AuditLog::query()->where('action', 'auth.login.failed')->count(),
            'auth.login.failed debe crear un audit log'
        );
    }

    public function test_failed_login_with_nonexistent_email_creates_audit_log(): void
    {
        [$tenant] = $this->makeTenantAndUser();

        $this->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => 'noexiste@example.test',
                'password' => 'cualquier-cosa',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login.failed',
            'entity_type' => 'system',
        ]);
    }

    public function test_issuing_token_creates_audit_log(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser();

        $countBefore = AuditLog::query()->where('action', 'auth.token.issued')->count();

        $this->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => 'audit@example.test',
                'password' => 'correct-password',
                'device_name' => 'test-device',
            ])
            ->assertCreated();

        $this->assertSame(
            $countBefore + 1,
            AuditLog::query()->where('action', 'auth.token.issued')->count(),
            'auth.token.issued debe crear un audit log'
        );
    }

    public function test_logout_creates_token_revoked_audit_log(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser();

        $loginResponse = $this->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/login', [
                'email' => 'audit@example.test',
                'password' => 'correct-password',
            ])
            ->assertCreated();

        $token = $loginResponse->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.token.revoked',
        ]);
    }
}