<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_update_and_deactivate_customer(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor', ['customers.create', 'customers.view', 'customers.update', 'customers.delete']);

        $customerId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/customers', [
                'name' => 'Maria Perez',
                'document_type' => Customer::DOCUMENT_V,
                'document_number' => '12345678',
                'phone' => '04141234567',
                'email' => 'maria@example.com',
                'fiscal_address' => 'Caracas',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Maria Perez')
            ->assertJsonPath('data.document_type', Customer::DOCUMENT_V)
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/customers/{$customerId}", [
                'phone' => '04147654321',
                'fiscal_address' => 'Valencia',
            ])
            ->assertOk()
            ->assertJsonPath('data.phone', '04147654321')
            ->assertJsonPath('data.fiscal_address', 'Valencia');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/customers/{$customerId}")
            ->assertNoContent();

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $tenant->id,
            'id' => $customerId,
            'is_active' => false,
        ]);
    }

    public function test_customer_document_is_unique_inside_company_but_can_repeat_between_companies(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantA, $userA, 'Vendedor A', ['customers.create', 'customers.view']);
        $this->grantRole($tenantB, $userB, 'Vendedor B', ['customers.create', 'customers.view']);

        $payload = [
            'name' => 'Cliente Compartido',
            'document_type' => Customer::DOCUMENT_V,
            'document_number' => '87654321',
        ];

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/customers', $payload)
            ->assertCreated();

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document_number']);

        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->postJson('/api/customers', $payload)
            ->assertCreated();
    }

    public function test_customers_do_not_mix_companies(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];
        $this->customer($tenantA, 'Cliente A', Customer::DOCUMENT_V, '111');
        $customerB = $this->customer($tenantB, 'Cliente B', Customer::DOCUMENT_V, '222');
        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Vendedor', ['customers.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Cliente A');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson("/api/customers/{$customerB->id}")
            ->assertForbidden();
    }

    public function test_customer_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/customers', [
                'name' => 'Cliente sin permiso',
                'document_type' => Customer::DOCUMENT_V,
                'document_number' => '999',
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

    private function customer(Tenant $tenant, string $name, string $documentType, string $documentNumber): Customer
    {
        $this->useTenant($tenant);

        return Customer::create([
            'name' => $name,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
        ]);
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
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
