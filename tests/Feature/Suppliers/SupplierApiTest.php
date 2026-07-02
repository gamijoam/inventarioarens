<?php

namespace Tests\Feature\Suppliers;

use App\Models\User;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupplierApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_update_and_deactivate_supplier(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Compras', ['suppliers.create', 'suppliers.view', 'suppliers.update', 'suppliers.delete']);

        $supplierId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/suppliers', [
                'name' => 'Proveedor Demo',
                'document_type' => Supplier::DOCUMENT_J,
                'document_number' => '123',
                'phone' => '02121234567',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Proveedor Demo')
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/suppliers/{$supplierId}", [
                'name' => 'Proveedor Actualizado',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Proveedor Actualizado');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/suppliers/{$supplierId}")
            ->assertNoContent();

        $this->assertDatabaseHas('suppliers', [
            'tenant_id' => $tenant->id,
            'id' => $supplierId,
            'is_active' => false,
        ]);
    }

    public function test_supplier_document_is_unique_inside_company_but_can_repeat_between_companies(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantA, $userA, 'Compras A', ['suppliers.create', 'suppliers.view']);
        $this->grantRole($tenantB, $userB, 'Compras B', ['suppliers.create', 'suppliers.view']);

        $payload = [
            'name' => 'Proveedor Mismo Rif',
            'document_type' => Supplier::DOCUMENT_J,
            'document_number' => '999',
        ];

        $this->actingAs($userA)->withHeader('X-Tenant', $tenantA->slug)->postJson('/api/suppliers', $payload)->assertCreated();
        $this->actingAs($userA)->withHeader('X-Tenant', $tenantA->slug)->postJson('/api/suppliers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document_number']);
        $this->actingAs($userB)->withHeader('X-Tenant', $tenantB->slug)->postJson('/api/suppliers', $payload)->assertCreated();
    }

    public function test_suppliers_do_not_mix_companies(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $this->supplier($tenantA, 'Proveedor A', Supplier::DOCUMENT_J, '111');
        $supplierB = $this->supplier($tenantB, 'Proveedor B', Supplier::DOCUMENT_J, '222');
        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Compras', ['suppliers.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/suppliers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Proveedor A');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson("/api/suppliers/{$supplierB->id}")
            ->assertForbidden();
    }

    public function test_supplier_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/suppliers', [
                'name' => 'Proveedor Demo',
                'document_type' => Supplier::DOCUMENT_J,
                'document_number' => '123',
            ])
            ->assertForbidden();
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function supplier(Tenant $tenant, string $name, string $documentType, string $documentNumber): Supplier
    {
        $this->useTenant($tenant);

        return Supplier::create([
            'name' => $name,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
        ]);
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): void
    {
        $this->useTenant($tenant);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
