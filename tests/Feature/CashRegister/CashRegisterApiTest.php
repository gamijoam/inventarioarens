<?php

namespace Tests\Feature\CashRegister;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CashRegisterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_open_cash_register_session(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['cash_register.open', 'cash_register.view']);

        $sessionId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/sessions', [
                'branch_id' => $branch->id,
                'opening_currency' => Product::CURRENCY_USD,
                'opening_amount' => 50,
                'notes' => 'Inicio de turno',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', CashRegisterSession::STATUS_OPEN)
            ->assertJsonPath('data.opening_base_amount', '50.0000')
            ->assertJsonPath('data.expected_base_amount', '50.0000')
            ->assertJsonPath('data.movements.0.type', CashRegisterMovement::TYPE_OPENING)
            ->json('data.id');

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'cash.session.opened',
            'aggregate_type' => 'cash_register_session',
            'aggregate_id' => $sessionId,
            'status' => 'pending',
        ]);
    }

    public function test_user_can_create_physical_cash_register_and_open_it(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['cash_register.create', 'cash_register.open', 'cash_register.view']);

        $cashRegisterId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/registers', [
                'branch_id' => $branch->id,
                'name' => 'Caja Mostrador 1',
                'code' => 'mostrador-1',
                'notes' => 'Caja principal de tienda.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Caja Mostrador 1')
            ->assertJsonPath('data.code', 'MOSTRADOR-1')
            ->assertJsonPath('data.status', CashRegister::STATUS_ACTIVE)
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/sessions', [
                'branch_id' => $branch->id,
                'cash_register_id' => $cashRegisterId,
                'opening_currency' => Product::CURRENCY_USD,
                'opening_amount' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('data.cash_register_id', $cashRegisterId)
            ->assertJsonPath('data.cash_register.name', 'Caja Mostrador 1');
    }

    public function test_user_can_open_cash_register_session_with_usd_and_ves_funds(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $cashRegister = $this->cashRegister($tenant, $branch, 'Caja Mostrador 1', 'CJ-1');
        $rateType = $this->rateType($tenant, 'BCV', 1000);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['cash_register.open', 'cash_register.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/sessions', [
                'branch_id' => $branch->id,
                'cash_register_id' => $cashRegister->id,
                'opening_base_amount' => 25,
                'opening_local_amount' => 5000,
                'exchange_rate_type_id' => $rateType->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.opening_base_amount', '30.0000')
            ->assertJsonPath('data.opening_local_amount', '5000.0000')
            ->assertJsonPath('data.expected_base_amount', '30.0000')
            ->assertJsonPath('data.expected_local_amount', '5000.0000')
            ->assertJsonCount(2, 'data.movements');
    }

    public function test_two_cashiers_cannot_open_the_same_physical_cash_register(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $cashRegister = $this->cashRegister($tenant, $branch, 'Caja Mostrador 1', 'CJ-1');
        $cashierA = $this->userInTenant($tenant);
        $cashierB = $this->userInTenant($tenant);
        $this->grantRole($tenant, $cashierA, 'Cajero A', ['cash_register.open']);
        $this->grantRole($tenant, $cashierB, 'Cajero B', ['cash_register.open']);

        $payload = [
            'branch_id' => $branch->id,
            'cash_register_id' => $cashRegister->id,
            'opening_amount' => 0,
        ];

        $this
            ->actingAs($cashierA)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/sessions', $payload)
            ->assertCreated();

        $this
            ->actingAs($cashierB)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/sessions', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cash_register_id']);
    }

    public function test_cashier_cannot_open_two_sessions_at_the_same_time(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['cash_register.open']);

        $payload = [
            'branch_id' => $branch->id,
            'opening_currency' => Product::CURRENCY_USD,
            'opening_amount' => 10,
        ];

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/sessions', $payload)
            ->assertCreated();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/sessions', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cashier_id']);
    }

    public function test_cash_register_sessions_index_filters_by_status_and_current_cashier(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $cashRegisterA = $this->cashRegister($tenant, $branch, 'Caja A', 'CJ-A');
        $cashRegisterB = $this->cashRegister($tenant, $branch, 'Caja B', 'CJ-B');
        $cashierA = $this->userInTenant($tenant);
        $cashierB = $this->userInTenant($tenant);
        $this->grantRole($tenant, $cashierA, 'Cajero A', ['cash_register.open', 'cash_register.close', 'cash_register.view']);
        $this->grantRole($tenant, $cashierB, 'Cajero B', ['cash_register.open', 'cash_register.view']);

        $openA = $this
            ->actingAs($cashierA)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/sessions', [
                'branch_id' => $branch->id,
                'cash_register_id' => $cashRegisterA->id,
                'opening_amount' => 0,
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($cashierA)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/cash-register/sessions/{$openA}/close", [
                'counted_amount' => 0,
            ])
            ->assertOk();

        $openB = $this
            ->actingAs($cashierB)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/sessions', [
                'branch_id' => $branch->id,
                'cash_register_id' => $cashRegisterB->id,
                'opening_amount' => 0,
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($cashierA)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/cash-register/sessions?status=open&cashier_id=me')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this
            ->actingAs($cashierB)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/cash-register/sessions?status=open&cashier_id=me')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $openB)
            ->assertJsonPath('data.0.status', CashRegisterSession::STATUS_OPEN);
    }

    public function test_creating_physical_cash_register_requires_create_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['cash_register.open', 'cash_register.view']);

        $payload = [
            'branch_id' => $branch->id,
            'name' => 'Caja Mostrador 2',
            'code' => 'mostrador-2',
        ];

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/registers', $payload)
            ->assertForbidden();

        $this->grantRole($tenant, $user, 'Admin Caja', ['cash_register.create', 'cash_register.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/registers', $payload)
            ->assertCreated()
            ->assertJsonPath('data.code', 'MOSTRADOR-2');
    }

    public function test_user_can_add_movements_and_close_cash_register_with_difference(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $rateType = $this->rateType($tenant, 'BCV', 500);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', [
            'cash_register.open',
            'cash_register.move',
            'cash_register.close',
            'cash_register.view',
        ]);

        $sessionId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/sessions', [
                'branch_id' => $branch->id,
                'opening_currency' => Product::CURRENCY_USD,
                'opening_amount' => 20,
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/cash-register/sessions/{$sessionId}/movements", [
                'type' => CashRegisterMovement::TYPE_INFLOW,
                'method' => CashRegisterMovement::METHOD_CASH,
                'currency' => Product::CURRENCY_VES,
                'amount' => 50000,
                'exchange_rate_type_id' => $rateType->id,
                'reference' => 'ING-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.expected_base_amount', '120.0000')
            ->assertJsonPath('data.expected_local_amount', '50000.0000');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/cash-register/sessions/{$sessionId}/movements", [
                'type' => CashRegisterMovement::TYPE_OUTFLOW,
                'method' => CashRegisterMovement::METHOD_CASH,
                'currency' => Product::CURRENCY_USD,
                'amount' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.expected_base_amount', '115.0000');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/cash-register/sessions/{$sessionId}/close", [
                'counted_currency' => Product::CURRENCY_USD,
                'counted_amount' => 110,
                'closing_notes' => 'Faltante reportado',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', CashRegisterSession::STATUS_CLOSED)
            ->assertJsonPath('data.counted_base_amount', '110.0000')
            ->assertJsonPath('data.difference_base_amount', '-5.0000');
    }

    public function test_closed_cash_register_rejects_new_movements(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', ['cash_register.open', 'cash_register.move', 'cash_register.close']);

        $sessionId = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/sessions', [
                'branch_id' => $branch->id,
                'opening_amount' => 0,
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/cash-register/sessions/{$sessionId}/close", [
                'counted_amount' => 0,
            ])
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/cash-register/sessions/{$sessionId}/movements", [
                'type' => CashRegisterMovement::TYPE_INFLOW,
                'method' => CashRegisterMovement::METHOD_CASH,
                'currency' => Product::CURRENCY_USD,
                'amount' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_cash_register_sessions_do_not_mix_companies_and_reject_foreign_branch(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']),
            Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']),
        ];
        $branchA = $this->branch($tenantA, 'A');
        $branchB = $this->branch($tenantB, 'B');
        $user = $this->userInTenant($tenantA);
        $this->grantRole($tenantA, $user, 'Cajero', ['cash_register.open', 'cash_register.view']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/cash-register/sessions', [
                'branch_id' => $branchA->id,
                'opening_amount' => 0,
            ])
            ->assertCreated();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/cash-register/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/cash-register/sessions', [
                'branch_id' => $branchB->id,
                'opening_amount' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id']);
    }

    public function test_cash_register_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $branch = $this->branch($tenant);
        $user = $this->userInTenant($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/sessions', [
                'branch_id' => $branch->id,
                'opening_amount' => 0,
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

    private function branch(Tenant $tenant, string $suffix = 'MAIN'): Branch
    {
        $this->useTenant($tenant);

        return Branch::create([
            'name' => "Sucursal {$suffix}",
            'code' => "BR-CASH-{$suffix}",
        ]);
    }

    private function cashRegister(Tenant $tenant, Branch $branch, string $name, string $code): CashRegister
    {
        $this->useTenant($tenant);

        return CashRegister::create([
            'branch_id' => $branch->id,
            'name' => $name,
            'code' => $code,
            'status' => CashRegister::STATUS_ACTIVE,
        ]);
    }

    private function rateType(Tenant $tenant, string $code, float $rate): ExchangeRateType
    {
        $this->useTenant($tenant);

        $rateType = ExchangeRateType::create(['code' => $code, 'name' => "Tasa {$code}", 'is_default' => true]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'rate' => $rate,
            'effective_at' => '2026-07-02 12:00:00',
            'is_active' => true,
        ]);

        return $rateType;
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
