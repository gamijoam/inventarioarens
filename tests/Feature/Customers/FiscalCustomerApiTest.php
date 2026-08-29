<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FiscalCustomerApiTest extends TestCase
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

    public function test_customer_can_store_fiscal_name_and_legacy_name_is_used_when_omitted(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Clientes', 'slug' => 'empresa-clientes']);
        $user = $this->userInTenant($tenant, ['customers.create', 'customers.view']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/customers', [
                'name' => 'Contacto Comercial',
                'fiscal_name' => 'Comercializadora Fiscal, C.A.',
                'document_type' => Customer::DOCUMENT_J,
                'document_number' => 'J-12345678-9',
                'fiscal_address' => 'Av. Principal, Local 1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Contacto Comercial')
            ->assertJsonPath('data.fiscal_name', 'Comercializadora Fiscal, C.A.');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/customers', [
                'name' => 'Cliente Sin Nombre Fiscal',
                'document_type' => Customer::DOCUMENT_V,
                'document_number' => '12345678',
            ])
            ->assertCreated()
            ->assertJsonPath('data.fiscal_name', 'Cliente Sin Nombre Fiscal');

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $tenant->id,
            'fiscal_name' => 'Comercializadora Fiscal, C.A.',
        ]);
    }

    public function test_customer_fiscal_name_is_validated_and_replicated_in_sync_payload(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Validacion', 'slug' => 'empresa-validacion']);
        $user = $this->userInTenant($tenant, ['customers.create', 'customers.update']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/customers', [
                'name' => 'Cliente',
                'document_type' => Customer::DOCUMENT_V,
                'document_number' => '123',
                'fiscal_name' => str_repeat('x', 256),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fiscal_name']);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/customers', [
                'name' => 'Cliente',
                'document_type' => Customer::DOCUMENT_V,
                'document_number' => '123',
                'fiscal_name' => 'Cliente Fiscal',
            ])
            ->assertCreated();

        $event = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'customer.created')
            ->first();
        $this->assertNotNull($event);
        $payload = json_decode((string) $event->payload, true);
        $this->assertSame('Cliente Fiscal', $payload['fiscal_name']);
    }

    private function userInTenant(Tenant $tenant, array $permissions): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $role = Role::findOrCreate('Fiscal Customer Test '.md5($tenant->id.implode('|', $permissions)), 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }
}
