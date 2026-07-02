<?php

namespace Tests\Feature\Currency;

use App\Models\User;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CurrencyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_bcv_and_parallel_rate_types_for_same_company(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Currency Manager', ['currency.view', 'currency.manage']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/currency/rate-types', [
                'code' => 'BCV',
                'name' => 'Tasa BCV',
                'is_default' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'BCV')
            ->assertJsonPath('data.is_default', true);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/currency/rate-types', [
                'code' => 'PARALELO',
                'name' => 'Tasa paralelo',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'PARALELO')
            ->assertJsonPath('data.is_default', false);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/currency/rate-types')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_current_rates_can_have_bcv_and_parallel_active_at_same_time(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $bcv = $this->rateTypeFor($tenant, 'BCV', 'Tasa BCV');
        $parallel = $this->rateTypeFor($tenant, 'PARALELO', 'Tasa paralelo');
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Currency Manager', ['currency.view', 'currency.manage']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/currency/rates', [
                'exchange_rate_type_id' => $bcv->id,
                'rate' => 500,
                'effective_at' => '2026-07-02T08:00:00-04:00',
                'is_active' => true,
                'source' => 'Manual',
            ])
            ->assertCreated()
            ->assertJsonPath('data.exchange_rate_type_code', 'BCV')
            ->assertJsonPath('data.rate', 500)
            ->assertJsonPath('data.is_active', true);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/currency/rates', [
                'exchange_rate_type_id' => $parallel->id,
                'rate' => 600,
                'effective_at' => '2026-07-02T08:05:00-04:00',
                'is_active' => true,
                'source' => 'Manual',
            ])
            ->assertCreated()
            ->assertJsonPath('data.exchange_rate_type_code', 'PARALELO')
            ->assertJsonPath('data.rate', 600)
            ->assertJsonPath('data.is_active', true);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/currency/rates/current')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_activating_new_rate_only_replaces_same_rate_type(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $bcv = $this->rateTypeFor($tenant, 'BCV', 'Tasa BCV');
        $parallel = $this->rateTypeFor($tenant, 'PARALELO', 'Tasa paralelo');
        $user = $this->userInTenant($tenant);

        $oldBcv = $this->rateFor($tenant, $bcv, 500, true);
        $newBcv = $this->rateFor($tenant, $bcv, 520, false);
        $parallelRate = $this->rateFor($tenant, $parallel, 600, true);
        $this->grantRole($tenant, $user, 'Currency Manager', ['currency.view', 'currency.manage']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/currency/rates/{$newBcv->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.exchange_rate_type_code', 'BCV')
            ->assertJsonPath('data.rate', 520)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('exchange_rates', ['id' => $oldBcv->id, 'is_active' => false]);
        $this->assertDatabaseHas('exchange_rates', ['id' => $newBcv->id, 'is_active' => true]);
        $this->assertDatabaseHas('exchange_rates', ['id' => $parallelRate->id, 'is_active' => true]);
    }

    public function test_user_can_deactivate_individual_rate_without_deleting_history(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $bcv = $this->rateTypeFor($tenant, 'BCV', 'Tasa BCV');
        $rate = $this->rateFor($tenant, $bcv, 500, true);
        $user = $this->userInTenant($tenant);

        $this->grantRole($tenant, $user, 'Currency Manager', ['currency.view', 'currency.manage']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/currency/rates/{$rate->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.exchange_rate_type_code', 'BCV')
            ->assertJsonPath('data.rate', 500)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('exchange_rates', [
            'id' => $rate->id,
            'tenant_id' => $tenant->id,
            'rate' => '500.000000',
            'is_active' => false,
        ]);
    }

    public function test_currency_data_does_not_mix_between_companies_and_rejects_foreign_type(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];

        $bcvA = $this->rateTypeFor($tenantA, 'BCV', 'Tasa BCV A');
        $bcvB = $this->rateTypeFor($tenantB, 'BCV', 'Tasa BCV B');
        $this->rateFor($tenantA, $bcvA, 500, true);
        $this->rateFor($tenantB, $bcvB, 700, true);
        $user = $this->userInTenant($tenantA);

        $this->grantRole($tenantA, $user, 'Currency Manager', ['currency.view', 'currency.manage']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/currency/rates/current')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.rate', 500);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/currency/rates', [
                'exchange_rate_type_id' => $bcvB->id,
                'rate' => 710,
                'effective_at' => '2026-07-02T10:00:00-04:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exchange_rate_type_id']);
    }

    public function test_currency_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/currency/rate-types', [
                'code' => 'BCV',
                'name' => 'Tasa BCV',
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

    private function rateTypeFor(Tenant $tenant, string $code, string $name): ExchangeRateType
    {
        $this->useTenant($tenant);

        return ExchangeRateType::create([
            'code' => $code,
            'name' => $name,
        ]);
    }

    private function rateFor(Tenant $tenant, ExchangeRateType $type, float $rate, bool $isActive): ExchangeRate
    {
        $this->useTenant($tenant);

        return ExchangeRate::create([
            'exchange_rate_type_id' => $type->id,
            'rate' => $rate,
            'effective_at' => '2026-07-02 12:00:00',
            'is_active' => $isActive,
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
