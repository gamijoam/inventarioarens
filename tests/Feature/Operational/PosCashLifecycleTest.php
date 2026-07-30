<?php

namespace Tests\Feature\Operational;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\SalesReturns\Models\SalesReturnItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PosCashLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_complete_credit_collection_return_and_balanced_close_in_one_operational_day(): void
    {
        $tenant = Tenant::create(['name' => 'Operacion integral', 'slug' => 'operacion-integral']);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen principal', 'code' => 'WH-MAIN']);
        $cashRegister = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Mostrador', 'code' => 'POS-01']);
        $rateType = ExchangeRateType::create(['name' => 'BCV', 'code' => 'BCV', 'is_default' => true]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'rate' => 500,
            'effective_at' => now(),
            'is_active' => true,
        ]);
        $product = Product::create([
            'name' => 'Producto operativo',
            'sku' => 'OPS-001',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
            'sale_exchange_rate_type_id' => $rateType->id,
        ]);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => 5,
        ]);
        $customer = Customer::create([
            'name' => 'Cliente operativo',
            'document_type' => Customer::DOCUMENT_V,
            'document_number' => '998877',
        ]);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $this->grantRole($tenant, $user, [
            'pos.view',
            'pos.checkout',
            'cash_register.open',
            'cash_register.close',
            'cash_register.view',
            'accounts_receivable.collect',
            'accounts_receivable.view',
            'sales_returns.create',
            'sales_returns.view',
            'sales_returns.review',
            'sales_returns.process',
        ]);

        $sessionId = $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/cash-register/sessions', [
                'branch_id' => $branch->id,
                'cash_register_id' => $cashRegister->id,
                'opening_base_amount' => 20,
                'opening_local_amount' => 5000,
                'exchange_rate_type_id' => $rateType->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.expected_base_amount', '30.0000')
            ->json('data.id');

        $checkout = $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $sessionId,
                'customer_id' => $customer->id,
                'credit' => true,
                'items' => [[
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 2,
                ]],
                'payments' => [[
                    'method' => PosPayment::METHOD_CASH,
                    'currency' => Product::CURRENCY_USD,
                    'amount' => 50,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.sale.status', Sale::STATUS_CONFIRMED)
            ->json('data');

        $account = AccountsReceivable::query()->where('sale_id', $checkout['sale_id'])->firstOrFail();
        $this->assertSame(AccountsReceivable::STATUS_PARTIAL, $account->status);
        $this->assertSame('150.0000', $account->balance_base_amount);
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '3.0000',
        ]);

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/accounts-receivable/{$account->id}/payments", [
                'payment_currency' => Product::CURRENCY_USD,
                'amount' => 150,
                'cash_register_session_id' => $sessionId,
                'method' => CashRegisterMovement::METHOD_TRANSFER,
                'reference' => 'OPS-CXC-001',
            ])
            ->assertCreated();

        $account->refresh();
        $this->assertSame(AccountsReceivable::STATUS_PAID, $account->status);
        $this->assertSame('0.0000', $account->balance_base_amount);

        $sale = Sale::query()->with('items')->findOrFail($checkout['sale_id']);
        $returnId = $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales-returns', [
                'sale_id' => $sale->id,
                'reason' => 'Prueba operativa de devolucion',
                'items' => [[
                    'sale_item_id' => $sale->items->first()->id,
                    'quantity' => 1,
                    'condition' => SalesReturnItem::CONDITION_SELLABLE,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', SalesReturn::STATUS_REQUESTED)
            ->json('data.id');

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/sales-returns/{$returnId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', SalesReturn::STATUS_APPROVED);

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/sales-returns/{$returnId}/process", ['refund_mode' => 'none'])
            ->assertOk()
            ->assertJsonPath('data.status', SalesReturn::STATUS_PROCESSED);

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '4.0000',
        ]);

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/cash-register/sessions/{$sessionId}/close", [
                'counted_base_amount' => 70,
                'counted_local_amount' => 5000,
                'exchange_rate_type_id' => $rateType->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', CashRegisterSession::STATUS_CLOSED)
            ->assertJsonPath('data.expected_base_amount', '230.0000')
            ->assertJsonPath('data.counted_base_amount', '80.0000')
            ->assertJsonPath('data.difference_base_amount', '-150.0000')
            ->assertJsonPath('data.difference_cash_usd', '0.0000')
            ->assertJsonPath('data.difference_cash_ves', '0.0000');
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function grantRole(Tenant $tenant, User $user, array $permissions): void
    {
        $this->useTenant($tenant);
        $role = Role::findOrCreate('Operador integral', 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
