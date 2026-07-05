<?php

namespace Tests\Feature\PaymentMethods;

use App\Models\User;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\PriceList;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PaymentMethodApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_update_list_and_deactivate_payment_methods(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Administrador pagos', ['payment_methods.view', 'payment_methods.update']);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/payment-methods', [
                'name' => 'Pago móvil',
                'code' => 'pm-ves',
                'method' => PosPayment::METHOD_MOBILE_PAYMENT,
                'currency_mode' => PaymentMethod::CURRENCY_VES,
                'requires_reference' => true,
                'sort_order' => 10,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'PM-VES')
            ->assertJsonPath('data.currency_mode', PaymentMethod::CURRENCY_VES)
            ->assertJsonPath('data.requires_reference', true);

        $id = $response->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/payment-methods/{$id}", [
                'name' => 'Pago móvil bancario',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Pago móvil bancario')
            ->assertJsonPath('data.is_active', false);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/payment-methods')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_payment_methods_do_not_mix_companies(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $userA = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $userA, 'Administrador A', ['payment_methods.view', 'payment_methods.update']);

        $this->useTenant($tenantB);
        PaymentMethod::create([
            'name' => 'Zelle B',
            'code' => 'ZELLE-B',
            'method' => PosPayment::METHOD_ZELLE,
            'currency_mode' => PaymentMethod::CURRENCY_USD,
        ]);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/payment-methods')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_price_lists_can_include_payment_methods(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Administrador precios', ['products.view']);
        $this->useTenant($tenant);

        $method = PaymentMethod::create([
            'name' => 'Efectivo USD',
            'code' => 'CASH-USD',
            'method' => PosPayment::METHOD_CASH,
            'currency_mode' => PaymentMethod::CURRENCY_USD,
            'requires_reference' => false,
            'is_active' => true,
        ]);
        $priceList = PriceList::create([
            'name' => 'Detal',
            'code' => 'DETAL',
            'is_default' => true,
            'is_active' => true,
        ]);
        $priceList->paymentMethods()->sync([$method->id => ['tenant_id' => $tenant->id]]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/price-lists')
            ->assertOk()
            ->assertJsonPath('data.0.payment_method_ids.0', $method->id)
            ->assertJsonPath('data.0.payment_methods.0.code', 'CASH-USD');
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

    private function grantRole(Tenant $tenant, User $user, string $name, array $permissions): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $role = Role::findOrCreate($name.' '.$tenant->id, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }
}
