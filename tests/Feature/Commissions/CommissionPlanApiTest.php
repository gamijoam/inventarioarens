<?php

namespace Tests\Feature\Commissions;

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

class CommissionPlanApiTest extends TestCase
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

    public function test_manager_creates_seller_plan_with_assignments_and_historical_rules(): void
    {
        [$tenant, $manager] = $this->tenantAndUser('empresa-a', [
            'commissions.view_all',
            'commissions.manage',
        ]);
        $seller = $this->userInTenant($tenant, 'vendedor@example.test');
        $rateType = $this->rateType($tenant, 'BCV', 60);

        $response = $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/commission-plans', [
                'name' => 'Vendedores 3%',
                'beneficiary_role' => 'seller',
                'percentage' => 3,
                'conversion_policy' => 'configured_rate',
                'exchange_rate_type_id' => $rateType->id,
                'credit_policy' => 'proportional_collections',
                'maturation_days' => 7,
                'allow_self_stacking' => false,
                'is_active' => true,
                'starts_at' => '2026-08-01 00:00:00',
                'user_ids' => [$seller->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Vendedores 3%')
            ->assertJsonPath('data.beneficiary_role', 'seller')
            ->assertJsonPath('data.percentage', '3.0000')
            ->assertJsonPath('data.exchange_rate_type.code', 'BCV')
            ->assertJsonPath('data.assignments.0.user.id', $seller->id);

        $this->assertDatabaseHas('commission_plans', [
            'tenant_id' => $tenant->id,
            'id' => $response->json('data.id'),
            'percentage' => '3.0000',
            'conversion_policy' => 'configured_rate',
            'credit_policy' => 'proportional_collections',
        ]);
        $this->assertDatabaseHas('commission_plan_assignments', [
            'tenant_id' => $tenant->id,
            'commission_plan_id' => $response->json('data.id'),
            'user_id' => $seller->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'commission_plan.created',
            'aggregate_type' => 'commission_plan',
            'aggregate_id' => $response->json('data.id'),
        ]);
    }

    public function test_plan_rejects_user_and_rate_type_from_another_tenant(): void
    {
        [$tenantA, $manager] = $this->tenantAndUser('empresa-a', ['commissions.manage']);
        [$tenantB, $foreignUser] = $this->tenantAndUser('empresa-b', []);
        $foreignRateType = $this->rateType($tenantB, 'PARALELO', 75);

        $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/commission-plans', [
                'name' => 'Plan invalido',
                'beneficiary_role' => 'seller',
                'percentage' => 3,
                'conversion_policy' => 'configured_rate',
                'exchange_rate_type_id' => $foreignRateType->id,
                'credit_policy' => 'proportional_collections',
                'user_ids' => [$foreignUser->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exchange_rate_type_id', 'user_ids.0']);
    }

    public function test_user_without_manage_permission_cannot_create_plan(): void
    {
        [$tenant, $user] = $this->tenantAndUser('empresa-a', ['commissions.view_own']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/commission-plans', [
                'name' => 'Plan no autorizado',
                'beneficiary_role' => 'cashier',
                'percentage' => 1,
                'conversion_policy' => 'sale_snapshot',
                'credit_policy' => 'sale_confirmation',
                'user_ids' => [$user->id],
            ])
            ->assertForbidden();
    }

    public function test_plan_from_another_tenant_is_not_visible_or_mutable(): void
    {
        [$tenantA, $managerA] = $this->tenantAndUser('empresa-a', ['commissions.manage', 'commissions.view_all']);
        [$tenantB, $managerB] = $this->tenantAndUser('empresa-b', ['commissions.manage', 'commissions.view_all']);

        $created = $this
            ->actingAs($managerA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/commission-plans', [
                'name' => 'Privado A',
                'beneficiary_role' => 'seller',
                'percentage' => 2,
                'conversion_policy' => 'sale_snapshot',
                'credit_policy' => 'sale_confirmation',
                'user_ids' => [$managerA->id],
            ])
            ->assertCreated();

        $planId = $created->json('data.id');

        $this
            ->actingAs($managerB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson("/api/commission-plans/{$planId}")
            ->assertNotFound();

        $this
            ->actingAs($managerB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->patchJson("/api/commission-plans/{$planId}", ['name' => 'Alterado'])
            ->assertNotFound();

        $this->assertDatabaseHas('commission_plans', [
            'tenant_id' => $tenantA->id,
            'id' => $planId,
            'name' => 'Privado A',
        ]);
    }

    public function test_simulator_uses_selected_active_rate_and_returns_snapshot(): void
    {
        [$tenant, $manager] = $this->tenantAndUser('empresa-a', ['commissions.manage']);
        $rateType = $this->rateType($tenant, 'BCV', 60);

        $response = $this
            ->actingAs($manager)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/commissions/simulate', [
                'amount' => 6000,
                'currency' => 'VES',
                'percentage' => 3,
                'exchange_rate_type_id' => $rateType->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.currency', 'VES')
            ->assertJsonPath('data.exchange_rate_type_code', 'BCV')
            ->assertJsonPath('data.exchange_rate', '60.000000')
            ->assertJsonPath('data.eligible_base_amount', '100.0000')
            ->assertJsonPath('data.commission_base_amount', '3.0000');

        $this->assertNotNull($response->json('data.rate_effective_at'));
    }

    private function tenantAndUser(string $slug, array $permissions): array
    {
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug]);
        $user = $this->userInTenant($tenant, "{$slug}@example.test");
        $this->grantRole($tenant, $user, $permissions);

        return [$tenant, $user];
    }

    private function userInTenant(Tenant $tenant, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $tenant->users()->attach($user->id, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, array $permissions): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('Comisiones '.$tenant->slug, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function rateType(Tenant $tenant, string $code, float $rate): ExchangeRateType
    {
        app(TenantManager::class)->set($tenant);
        $type = ExchangeRateType::create([
            'code' => $code,
            'name' => $code,
            'is_default' => true,
            'is_active' => true,
        ]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $type->id,
            'base_currency' => 'USD',
            'quote_currency' => 'VES',
            'rate' => $rate,
            'effective_at' => '2026-08-14 08:00:00',
            'is_active' => true,
        ]);

        return $type;
    }
}
