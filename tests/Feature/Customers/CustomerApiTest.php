<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'customer.created',
            'aggregate_type' => 'customer',
            'aggregate_id' => $customerId,
            'status' => 'pending',
        ]);

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

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'customer.updated',
            'aggregate_type' => 'customer',
            'aggregate_id' => $customerId,
            'status' => 'pending',
        ]);

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

        $this->assertSame(
            2,
            DB::table('sync_outbox')
                ->where('tenant_id', $tenant->id)
                ->where('event_type', 'customer.updated')
                ->where('aggregate_type', 'customer')
                ->where('aggregate_id', $customerId)
                ->count()
        );
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

    public function test_user_can_search_active_customers_for_pos(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $otherTenant = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor', ['customers.view']);

        $this->customer($tenant, 'Maria Telefonos', Customer::DOCUMENT_V, '12345678', '04141234567');
        $inactive = $this->customer($tenant, 'Maria Inactiva', Customer::DOCUMENT_V, '88888888', '04140000000');
        $inactive->update(['is_active' => false]);
        $this->customer($tenant, 'Pedro Accesorios', Customer::DOCUMENT_V, '99999999', '04149999999');
        $this->customer($otherTenant, 'Maria Otra Empresa', Customer::DOCUMENT_V, '12345678', '04141234567');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/customers?search=12345678&active_only=1&limit=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Maria Telefonos');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/customers?search=0414&active_only=1&limit=10')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Maria Telefonos')
            ->assertJsonPath('data.1.name', 'Pedro Accesorios');
    }

    public function test_customer_detail_can_include_pos_history(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $otherTenant = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor', ['customers.view']);

        $customer = $this->customer($tenant, 'Cliente Historial', Customer::DOCUMENT_V, '123');
        $otherCustomer = $this->customer($otherTenant, 'Cliente Externo', Customer::DOCUMENT_V, '123');

        $this->posOrder($tenant, $customer, PosOrder::STATUS_PAID, 80, 80, now()->subDays(2));
        $this->posOrder($tenant, $customer, PosOrder::STATUS_OPEN, 50, 20, now()->subDay());
        $this->posOrder($otherTenant, $otherCustomer, PosOrder::STATUS_PAID, 999, 999, now());
        $this->useTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/customers/{$customer->id}?include=pos_history")
            ->assertOk()
            ->assertJsonPath('data.name', 'Cliente Historial')
            ->assertJsonPath('data.pos_history.total_orders', 2)
            ->assertJsonPath('data.pos_history.paid_orders', 1)
            ->assertJsonPath('data.pos_history.open_orders', 1)
            ->assertJsonPath('data.pos_history.total_base_amount', 130)
            ->assertJsonPath('data.pos_history.paid_base_amount', 100)
            ->assertJsonPath('data.pos_history.balance_base_amount', 30)
            ->assertJsonPath('data.pos_history.recent_orders.0.status', PosOrder::STATUS_OPEN)
            ->assertJsonMissing(['total_base_amount' => 999]);
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

    private function customer(Tenant $tenant, string $name, string $documentType, string $documentNumber, ?string $phone = null): Customer
    {
        $this->useTenant($tenant);

        return Customer::create([
            'name' => $name,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'phone' => $phone,
        ]);
    }

    private function posOrder(Tenant $tenant, Customer $customer, string $status, int $total, int $paid, \DateTimeInterface $openedAt): PosOrder
    {
        $this->useTenant($tenant);

        $sale = Sale::create([
            'customer_id' => $customer->id,
            'status' => $status === PosOrder::STATUS_PAID ? 'confirmed' : 'draft',
            'total_base_amount' => $total,
            'total_local_amount' => $total * 500,
            'confirmed_at' => $status === PosOrder::STATUS_PAID ? $openedAt : null,
        ]);

        return PosOrder::create([
            'sale_id' => $sale->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'customer_name' => $customer->name,
            'total_base_amount' => $total,
            'total_local_amount' => $total * 500,
            'paid_base_amount' => $paid,
            'paid_local_amount' => $paid * 500,
            'opened_at' => $openedAt,
            'paid_at' => $status === PosOrder::STATUS_PAID ? $openedAt : null,
            'closed_at' => $status === PosOrder::STATUS_PAID ? $openedAt : null,
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
