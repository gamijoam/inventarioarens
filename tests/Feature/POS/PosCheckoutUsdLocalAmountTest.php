<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\POS\Models\PosOrder;
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

class PosCheckoutUsdLocalAmountTest extends TestCase
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

    public function test_usd_payment_stores_amount_local_not_null_when_active_rate_exists(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda USD', 'slug' => 'tienda-usd']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', [
            'pos.view', 'pos.checkout',
            'sales.view', 'sales.create', 'sales.cancel',
            'cash_register.view', 'cash_register.open',
            'cash_register.move', 'cash_register.close',
            'accounts_receivable.view', 'accounts_receivable.collect',
        ]);

        $rateType = ExchangeRateType::create(['code' => 'BCV', 'name' => 'Tasa BCV', 'is_default' => true]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'rate' => 36.50,
            'effective_at' => '2026-07-02 12:00:00',
            'is_active' => true,
        ]);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-USD']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'WH-USD']);
        $cashRegister = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja 1', 'code' => 'CR-USD-1', 'status' => CashRegister::STATUS_ACTIVE]);
        $session = CashRegisterSession::create([
            'branch_id' => $branch->id,
            'cash_register_id' => $cashRegister->id,
            'cashier_id' => $user->id,
            'opened_by' => $user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);

        $product = Product::create([
            'name' => 'Producto USD',
            'sku' => 'PROD-USD-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        app(InventoryMovementService::class)->purchase($warehouse, $product, 10, 50, $user, 'Stock POS USD test');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $session->id,
                'items' => [
                    ['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 1],
                ],
                'payments' => [
                    [
                        'method' => PosPayment::METHOD_CASH,
                        'currency' => Product::CURRENCY_USD,
                        'amount' => 100,
                        'status' => PosPayment::STATUS_CAPTURED,
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.paid_base_amount', '100.0000');

        $payment = PosPayment::query()
            ->where('currency', Product::CURRENCY_USD)
            ->where('method', PosPayment::METHOD_CASH)
            ->firstOrFail();

        $this->assertSame('100.0000', (string) $payment->amount, 'amount debe ser 100 USD');
        $this->assertSame('100.0000', (string) $payment->amount_base, 'amount_base debe ser 100 USD');
        $this->assertSame(
            '3650.0000',
            (string) $payment->amount_local,
            'amount_local debe ser 100 * 36.50 = 3650 VES, NO null'
        );
        $this->assertSame('36.500000', (string) $payment->exchange_rate);
        $this->assertSame('BCV', $payment->exchange_rate_type_code);

        $order = PosOrder::query()->findOrFail($payment->pos_order_id);
        $this->assertSame('100.0000', (string) $order->paid_base_amount);
        $this->assertSame('3650.0000', (string) $order->paid_local_amount);
        $this->assertSame(PosOrder::STATUS_PAID, $order->status);
    }

    public function test_usd_payment_rejects_when_no_active_rate_exists(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Sin Tasa', 'slug' => 'tienda-sin-tasa']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', [
            'pos.view', 'pos.checkout',
            'sales.view', 'sales.create', 'sales.cancel',
            'cash_register.view', 'cash_register.open',
            'cash_register.move', 'cash_register.close',
            'accounts_receivable.view', 'accounts_receivable.collect',
        ]);

        $rateType = ExchangeRateType::create(['code' => 'BCV', 'name' => 'Tasa BCV', 'is_default' => true]);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-NORATE']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'WH-NORATE']);
        $cashRegister = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja 1', 'code' => 'CR-NORATE-1', 'status' => CashRegister::STATUS_ACTIVE]);
        $session = CashRegisterSession::create([
            'branch_id' => $branch->id,
            'cash_register_id' => $cashRegister->id,
            'cashier_id' => $user->id,
            'opened_by' => $user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);

        $product = Product::create([
            'name' => 'Producto Sin Tasa',
            'sku' => 'PROD-NORATE-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 50,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        app(InventoryMovementService::class)->purchase($warehouse, $product, 5, 25, $user, 'Stock sin tasa');

        $countOrdersBefore = PosOrder::query()->count();

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $session->id,
                'items' => [
                    ['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 1],
                ],
                'payments' => [
                    [
                        'method' => PosPayment::METHOD_CASH,
                        'currency' => Product::CURRENCY_USD,
                        'amount' => 50,
                        'status' => PosPayment::STATUS_CAPTURED,
                    ],
                ],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['payments']);

        $countOrdersAfter = PosOrder::query()->count();
        $this->assertSame(
            $countOrdersBefore,
            $countOrdersAfter,
            'PosOrder NO debe haberse creado (transaccion rollback)'
        );
    }

    public function test_usd_payment_uses_explicit_rate_type_when_provided(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda Multi Rate', 'slug' => 'tienda-multi-rate']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Cajero', [
            'pos.view', 'pos.checkout',
            'sales.view', 'sales.create', 'sales.cancel',
            'cash_register.view', 'cash_register.open',
            'cash_register.move', 'cash_register.close',
            'accounts_receivable.view', 'accounts_receivable.collect',
        ]);

        $bcvType = ExchangeRateType::create(['code' => 'BCV', 'name' => 'Tasa BCV', 'is_default' => true]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $bcvType->id,
            'rate' => 36.50,
            'effective_at' => '2026-07-02 12:00:00',
            'is_active' => true,
        ]);
        $parallelType = ExchangeRateType::create(['code' => 'PARALELO', 'name' => 'Tasa Paralelo']);
        ExchangeRate::create([
            'exchange_rate_type_id' => $parallelType->id,
            'rate' => 42.00,
            'effective_at' => '2026-07-02 12:00:00',
            'is_active' => true,
        ]);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'BR-MR']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'WH-MR']);
        $cashRegister = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja 1', 'code' => 'CR-MR-1', 'status' => CashRegister::STATUS_ACTIVE]);
        $session = CashRegisterSession::create([
            'branch_id' => $branch->id,
            'cash_register_id' => $cashRegister->id,
            'cashier_id' => $user->id,
            'opened_by' => $user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);

        $product = Product::create([
            'name' => 'Producto Multi Rate',
            'sku' => 'PROD-MR-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        app(InventoryMovementService::class)->purchase($warehouse, $product, 10, 50, $user, 'Stock multi rate');

        $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $session->id,
                'items' => [
                    ['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 1],
                ],
                'payments' => [
                    [
                        'method' => PosPayment::METHOD_CASH,
                        'currency' => Product::CURRENCY_USD,
                        'amount' => 100,
                        'status' => PosPayment::STATUS_CAPTURED,
                        'exchange_rate_type_id' => $parallelType->id,
                    ],
                ],
            ])
            ->assertCreated();

        $payment = PosPayment::query()->where('currency', Product::CURRENCY_USD)->firstOrFail();
        $this->assertSame(
            '4200.0000',
            (string) $payment->amount_local,
            'amount_local debe usar el rate PARALELO (42.00), no BCV (36.50)'
        );
        $this->assertSame('PARALELO', $payment->exchange_rate_type_code);
    }

    private function userInTenant(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $user;
    }

    private function grantRole(Tenant $tenant, User $user, string $roleName, array $permissions): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }
}
