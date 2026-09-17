<?php

namespace Tests\Feature\CashRegister;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CashRegisterPosReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_cash_usd_sales_closed_at_zero_report_shortage_equal_to_sales(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openEmptySession($ctx);

        $this->checkout($ctx, $sessionId, 'cash', Product::CURRENCY_USD, 100);
        $this->checkout($ctx, $sessionId, 'cash', Product::CURRENCY_USD, 100);

        $close = $this->closeSession($ctx, $sessionId, 0, 0);

        $this->assertSame('200.0000', $close['expected_cash_usd'], 'Efectivo USD esperado debe ser igual a las ventas cobradas en efectivo.');
        $this->assertSame('-200.0000', $close['difference_cash_usd'], 'El faltante fisico USD debe ser exactamente lo facturado en efectivo.');
        $this->assertSame('0.0000', $close['difference_cash_ves']);
    }

    public function test_two_cash_ves_sales_closed_at_zero_report_shortage_equal_to_sales(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_VES, 500);
        $sessionId = $this->openEmptySession($ctx);

        $this->checkout($ctx, $sessionId, 'cash', Product::CURRENCY_VES, 50000);
        $this->checkout($ctx, $sessionId, 'cash', Product::CURRENCY_VES, 50000);

        $close = $this->closeSession($ctx, $sessionId, 0, 0);

        $this->assertSame('100000.0000', $close['expected_cash_ves'], 'Efectivo VES esperado debe ser igual a las ventas cobradas en efectivo en Bs.');
        $this->assertSame('-100000.0000', $close['difference_cash_ves'], 'El faltante fisico VES debe ser exactamente lo facturado en efectivo en Bs.');
        $this->assertSame('0.0000', $close['difference_cash_usd']);
    }

    public function test_non_cash_sales_do_not_inflate_the_physical_cash_shortage(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openEmptySession($ctx);

        $this->checkout($ctx, $sessionId, 'cash', Product::CURRENCY_USD, 100);
        $this->checkout($ctx, $sessionId, 'transfer', Product::CURRENCY_USD, 100);

        $close = $this->closeSession($ctx, $sessionId, 0, 0);

        $this->assertSame('100.0000', $close['expected_cash_usd'], 'Solo la venta en efectivo debe contar como efectivo esperado.');
        $this->assertSame('-100.0000', $close['difference_cash_usd'], 'La venta por transferencia NO debe sumar faltante fisico.');
        $this->assertSame('-200.0000', $close['difference_base_amount'], 'La diferencia base acumula efectivo + electronico (total, no fisico).');
    }

    public function test_electronic_only_session_has_no_physical_cash_shortage(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openEmptySession($ctx);

        $this->checkout($ctx, $sessionId, 'mobile_payment', Product::CURRENCY_VES, 50000);
        $this->checkout($ctx, $sessionId, 'card', Product::CURRENCY_VES, 50000);

        $close = $this->closeSession($ctx, $sessionId, 0, 0);

        $this->assertSame('0.0000', $close['expected_cash_usd'], 'Un turno solo electronico no espera efectivo USD.');
        $this->assertSame('0.0000', $close['expected_cash_ves'], 'Un turno solo electronico no espera efectivo VES.');
        $this->assertSame('0.0000', $close['difference_cash_usd'], 'No hay faltante fisico USD si todo fue electronico.');
        $this->assertSame('0.0000', $close['difference_cash_ves'], 'No hay faltante fisico VES si todo fue electronico.');
        $this->assertSame('-200.0000', $close['difference_base_amount'], 'La diferencia base si refleja el total no contado en efectivo.');
    }

    public function test_physical_cash_shortage_matches_sales_even_when_money_is_counted_correctly(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openEmptySession($ctx);

        $this->checkout($ctx, $sessionId, 'cash', Product::CURRENCY_USD, 100);
        $this->checkout($ctx, $sessionId, 'cash', Product::CURRENCY_USD, 100);

        $close = $this->closeSession($ctx, $sessionId, 200, 0);

        $this->assertSame('0.0000', $close['difference_cash_usd'], 'Si se cuenta el efectivo de las dos ventas, no debe haber faltante.');
        $this->assertSame('0.0000', $close['difference_base_amount']);
    }

    /**
     * @return array{tenant: Tenant, branch: Branch, warehouse: Warehouse, cashier: User, product: Product, rateType: ExchangeRateType}
     */
    private function bootContext(string $saleCurrency, float $rate): array
    {
        $tenant = Tenant::create(['name' => 'Caja POS', 'slug' => 'caja-pos-'.uniqid()]);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN-'.uniqid()]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'WH-'.uniqid()]);
        $cashRegister = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja 1', 'code' => 'C1-'.uniqid()]);
        $rateType = ExchangeRateType::create(['name' => 'BCV', 'code' => 'BCV', 'is_default' => true]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'rate' => $rate,
            'effective_at' => now(),
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Producto caja',
            'sku' => 'CASH-'.uniqid(),
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => $saleCurrency,
            'sale_exchange_rate_type_id' => $rateType->id,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 50,
        ]);

        $cashier = User::factory()->create();
        $cashier->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $cashier, [
            'pos.view',
            'pos.checkout',
            'cash_register.open',
            'cash_register.close',
            'cash_register.view',
        ]);

        return compact('tenant', 'branch', 'warehouse', 'cashier', 'product', 'rateType') + ['cashRegister' => $cashRegister];
    }

    /**
     * @param  array{tenant: Tenant, branch: Branch, cashier: User}  $ctx
     */
    private function openEmptySession(array $ctx): int
    {
        return (int) $this
            ->actingAs($ctx['cashier'])
            ->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson('/api/cash-register/sessions', [
                'branch_id' => $ctx['branch']->id,
                'cash_register_id' => $ctx['cashRegister']->id,
                'opening_base_amount' => 0,
                'opening_local_amount' => 0,
                'exchange_rate_type_id' => $ctx['rateType']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.expected_cash_usd', '0.0000')
            ->assertJsonPath('data.expected_cash_ves', '0.0000')
            ->json('data.id');
    }

    /**
     * @param  array{tenant: Tenant, warehouse: Warehouse, cashier: User, product: Product}  $ctx
     */
    private function checkout(array $ctx, int $sessionId, string $method, string $currency, float $amount): void
    {
        $this->useTenant($ctx['tenant']);

        $customer = Customer::create([
            'name' => 'Cliente '.uniqid(),
            'document_type' => Customer::DOCUMENT_V,
            'document_number' => (string) random_int(100000, 999999),
        ]);

        $this
            ->actingAs($ctx['cashier'])
            ->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $sessionId,
                'customer_id' => $customer->id,
                'items' => [[
                    'warehouse_id' => $ctx['warehouse']->id,
                    'product_id' => $ctx['product']->id,
                    'quantity' => 1,
                ]],
                'payments' => [[
                    'method' => $method,
                    'currency' => $currency,
                    'amount' => $amount,
                ]],
            ])
            ->assertCreated();
    }

    /**
     * @param  array{tenant: Tenant, cashier: User, rateType: ExchangeRateType}  $ctx
     * @return array<string, mixed>
     */
    private function closeSession(array $ctx, int $sessionId, float $usd, float $ves): array
    {
        return $this
            ->actingAs($ctx['cashier'])
            ->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->patchJson("/api/cash-register/sessions/{$sessionId}/close", [
                'counted_base_amount' => $usd,
                'counted_local_amount' => $ves,
                'counted_cash_usd' => $usd,
                'counted_cash_ves' => $ves,
                'exchange_rate_type_id' => $ctx['rateType']->id,
                'closing_notes' => 'Arqueo de auditoria',
                'counting_mode' => CashRegisterSession::COUNTING_STANDARD,
            ])
            ->assertOk()
            ->json('data');
    }

    private function grantRole(Tenant $tenant, User $user, array $permissions): void
    {
        $this->useTenant($tenant);
        $role = Role::findOrCreate('Cajero auditoria', 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }
}
