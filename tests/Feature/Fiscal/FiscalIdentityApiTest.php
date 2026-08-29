<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FiscalIdentityApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_member_can_read_tenant_and_branch_fiscal_identity(): void
    {
        $tenant = Tenant::create(['name' => 'Comercial Arens', 'slug' => 'comercial-arens']);
        $branch = $this->branch($tenant, 'CCS');
        $user = $this->userInTenant($tenant);

        $this->useTenant($tenant);
        $tenant->setting()->update([
            'settings' => [
                'company' => [
                    'razon_social' => 'Comercial Arens, C.A.',
                    'rif' => 'J-12345678-9',
                    'domicilio_fiscal' => 'Av. Principal 1',
                    'tax_condition' => 'ordinary',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/fiscal/identity')
            ->assertOk()
            ->assertJsonPath('data.tenant.legal_name', 'Comercial Arens, C.A.')
            ->assertJsonPath('data.tenant.tax_id', 'J-12345678-9')
            ->assertJsonPath('data.tenant.tax_condition', 'ordinary')
            ->assertJsonPath('data.branches.0.id', $branch->id)
            ->assertJsonPath('data.branches.0.code', 'CCS')
            ->assertJsonPath('data.branches.0.fiscal_address', null);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/fiscal/identity/branches/{$branch->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $branch->id)
            ->assertJsonPath('data.name', 'Sucursal CCS');
    }

    public function test_manager_can_update_tenant_identity_and_emit_sync_event(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Fiscal', 'slug' => 'empresa-fiscal']);
        $user = $this->userInTenant($tenant, ['settings.manage']);

        $this->useTenant($tenant);
        DB::table('sync_outbox')->delete();

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/fiscal/identity', [
                'legal_name' => 'Empresa Fiscal, C.A.',
                'tax_id' => 'J-87654321-0',
                'fiscal_address' => 'Calle Fiscal 10',
                'city' => 'Caracas',
                'state' => 'Distrito Capital',
                'phone' => '+58 212 555 0000',
                'email' => 'fiscal@empresa.test',
                'tax_condition' => 'ordinary',
            ])
            ->assertOk()
            ->assertJsonPath('data.tenant.legal_name', 'Empresa Fiscal, C.A.')
            ->assertJsonPath('data.tenant.tax_id', 'J-87654321-0')
            ->assertJsonPath('data.tenant.tax_condition', 'ordinary');

        $stored = json_decode((string) DB::table('tenant_settings')->where('tenant_id', $tenant->id)->value('settings'), true);
        $this->assertSame('Empresa Fiscal, C.A.', $stored['company']['razon_social']);
        $this->assertSame('J-87654321-0', $stored['company']['rif']);
        $this->assertSame('ordinary', $stored['company']['tax_condition']);

        $event = DB::table('sync_outbox')->where('event_type', 'tenant_settings.updated')->latest('id')->first();
        $this->assertNotNull($event);
        $payload = json_decode((string) $event->payload, true);
        $this->assertSame('J-87654321-0', $payload['company']['rif']);
        $this->assertSame('ordinary', $payload['company']['tax_condition']);
    }

    public function test_manager_can_update_branch_identity_and_emit_sync_event(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Sucursales', 'slug' => 'empresa-sucursales']);
        $branch = $this->branch($tenant, 'VAL');
        $user = $this->userInTenant($tenant, ['settings.manage']);

        $this->useTenant($tenant);
        DB::table('sync_outbox')->delete();

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/fiscal/identity/branches/{$branch->id}", [
                'fiscal_address' => 'Av. Bolivar, Local 2',
                'city' => 'Valencia',
                'state' => 'Carabobo',
                'phone' => '+58 241 555 0000',
                'email' => 'valencia@empresa.test',
                'tax_condition' => 'formal',
            ])
            ->assertOk()
            ->assertJsonPath('data.fiscal_address', 'Av. Bolivar, Local 2')
            ->assertJsonPath('data.tax_condition', 'formal');

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'tenant_id' => $tenant->id,
            'fiscal_address' => 'Av. Bolivar, Local 2',
            'tax_condition' => 'formal',
        ]);

        $event = DB::table('sync_outbox')->where('event_type', 'branch.updated')->latest('id')->first();
        $this->assertNotNull($event);
        $payload = json_decode((string) $event->payload, true);
        $this->assertSame('Av. Bolivar, Local 2', $payload['fiscal_address']);
        $this->assertSame('formal', $payload['tax_condition']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/fiscal/identity/branches/{$branch->id}", [
                'fiscal_address' => null,
                'tax_condition' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.fiscal_address', null)
            ->assertJsonPath('data.tax_condition', null);
    }

    public function test_member_without_settings_manage_cannot_update_fiscal_identity(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Restringida', 'slug' => 'empresa-restringida']);
        $user = $this->userInTenant($tenant);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/fiscal/identity', ['legal_name' => 'No permitido'])
            ->assertForbidden();
    }

    public function test_branch_from_another_tenant_is_not_exposed(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $branchB = $this->branch($tenantB, 'B');
        $userA = $this->userInTenant($tenantA);

        $this->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson("/api/fiscal/identity/branches/{$branchB->id}")
            ->assertNotFound();
    }

    public function test_fiscal_identity_rejects_invalid_tax_data(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Validacion', 'slug' => 'empresa-validacion']);
        $user = $this->userInTenant($tenant, ['settings.manage']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/fiscal/identity', [
                'tax_id' => 'invalid-rif',
                'tax_condition' => 'unknown',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tax_id', 'tax_condition']);
    }

    private function branch(Tenant $tenant, string $code): Branch
    {
        $this->useTenant($tenant);

        return Branch::create([
            'name' => "Sucursal {$code}",
            'code' => $code,
        ]);
    }

    private function userInTenant(Tenant $tenant, array $permissions = []): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this->useTenant($tenant);
        $role = Role::findOrCreate('Fiscal Test '.md5($tenant->id.implode('|', $permissions)), 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
