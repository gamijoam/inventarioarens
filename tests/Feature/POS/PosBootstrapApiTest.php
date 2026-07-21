<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Models\PriceList;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PosBootstrapApiTest extends TestCase
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

    public function test_bootstrap_returns_warehouses_payment_methods_price_lists_rates_and_open_session(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa POS', 'slug' => 'empresa-pos-bootstrap']);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Sucursal POS', 'code' => 'BR-POS']);
        Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen POS', 'code' => 'WH-POS']);
        $register = CashRegister::create([
            'branch_id' => $branch->id,
            'code' => 'CR-POS',
            'name' => 'Caja POS',
            'status' => CashRegister::STATUS_ACTIVE,
        ]);
        $cashier = User::factory()->create();
        $cashier->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $cashier, 'Cajero', ['pos.view', 'pos.checkout']);

        CashRegisterSession::create([
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'cashier_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);

        $listDefault = PriceList::create([
            'code' => 'LISTA-POS',
            'name' => 'Lista POS',
            'is_default' => true,
            'is_active' => true,
        ]);
        PriceList::create(['code' => 'LISTA-MAYOR', 'name' => 'Mayorista', 'is_active' => true]);

        $rateType = ExchangeRateType::create([
            'code' => 'BCV-POS',
            'name' => 'BCV POS',
            'is_default' => true,
            'is_active' => true,
        ]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'base_currency' => ExchangeRate::BASE_USD,
            'quote_currency' => ExchangeRate::QUOTE_VES,
            'rate' => 36.5,
            'effective_at' => now(),
            'is_active' => true,
        ]);

        PaymentMethod::create([
            'code' => 'CASH-POS',
            'name' => 'Efectivo POS',
            'method' => 'cash',
            'currency_mode' => PaymentMethod::CURRENCY_FLEXIBLE,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $response = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/pos/bootstrap')
            ->assertOk();

        $response
            ->assertJsonCount(1, 'warehouses')
            ->assertJsonPath('warehouses.0.code', 'WH-POS')
            ->assertJsonCount(1, 'cash_registers')
            ->assertJsonPath('cash_registers.0.code', 'CR-POS')
            ->assertJsonCount(2, 'price_lists')
            ->assertJsonPath('price_lists.0.is_default', true)
            ->assertJsonCount(1, 'exchange_rate_types')
            ->assertJsonCount(1, 'exchange_rates')
            ->assertJsonPath('exchange_rates.0.rate', 36.5)
            ->assertJsonCount(1, 'payment_methods')
            ->assertJsonPath('open_session.status', CashRegisterSession::STATUS_OPEN)
            ->assertJsonPath('open_session.cashier_id', $cashier->id);
    }

    public function test_bootstrap_open_session_is_null_when_no_active_session(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa POS Sin Caja', 'slug' => 'empresa-pos-sin-caja']);
        $this->useTenant($tenant);
        $cashier = User::factory()->create();
        $cashier->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $cashier, 'Cajero', ['pos.view']);

        $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/pos/bootstrap')
            ->assertOk()
            ->assertJsonPath('open_session', null);
    }

    public function test_bootstrap_excludes_inactive_payment_methods_and_price_lists(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa POS Inactivos', 'slug' => 'empresa-pos-inactivos']);
        $this->useTenant($tenant);
        $cashier = User::factory()->create();
        $cashier->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $cashier, 'Cajero', ['pos.view']);

        PriceList::create(['code' => 'LISTA-ACT', 'name' => 'Activa', 'is_active' => true]);
        PriceList::create(['code' => 'LISTA-INACT', 'name' => 'Inactiva', 'is_active' => false]);

        PaymentMethod::create([
            'code' => 'PM-ACT',
            'name' => 'PM Activo',
            'method' => 'cash',
            'currency_mode' => PaymentMethod::CURRENCY_FLEXIBLE,
            'is_active' => true,
        ]);
        PaymentMethod::create([
            'code' => 'PM-INACT',
            'name' => 'PM Inactivo',
            'method' => 'cash',
            'currency_mode' => PaymentMethod::CURRENCY_FLEXIBLE,
            'is_active' => false,
        ]);

        $response = $this
            ->actingAs($cashier)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/pos/bootstrap')
            ->assertOk();

        $response->assertJsonCount(1, 'price_lists');
        $response->assertJsonPath('price_lists.0.code', 'LISTA-ACT');
        $response->assertJsonCount(1, 'payment_methods');
        $response->assertJsonPath('payment_methods.0.code', 'PM-ACT');
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
