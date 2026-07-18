<?php

namespace Tests\Feature\AccountsReceivable;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Models\AccountsReceivablePayment;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use App\Modules\SalesReturns\Services\SalesReturnService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AccountsReceivableApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_sale_creates_pending_account_receivable(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, 'AR-001');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Ventas', ['sales.create', 'accounts_receivable.view']);
        $sale = $this->confirmedSale($tenant, $user, $warehouse, $product, 2);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/accounts-receivable')
            ->assertOk()
            ->assertJsonPath('data.0.sale_id', $sale->id)
            ->assertJsonPath('data.0.status', AccountsReceivable::STATUS_PENDING)
            ->assertJsonPath('data.0.original_base_amount', '200.0000')
            ->assertJsonPath('data.0.balance_base_amount', '200.0000');
    }

    public function test_user_can_register_partial_and_total_usd_collections(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, 'AR-002');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Ventas', ['sales.create', 'accounts_receivable.collect', 'accounts_receivable.view']);
        $sale = $this->confirmedSale($tenant, $user, $warehouse, $product, 2);
        $account = AccountsReceivable::query()->where('sale_id', $sale->id)->firstOrFail();
        $session = $this->openCashSession($tenant, $user, $warehouse);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/accounts-receivable/{$account->id}/payments", [
                'payment_currency' => Product::CURRENCY_USD,
                'amount' => 80,
                'cash_register_session_id' => $session->id,
                'method' => 'transferencia',
                'reference' => 'COBRO-001',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount_base', '80.0000');

        $this->assertDatabaseHas('accounts_receivables', [
            'tenant_id' => $tenant->id,
            'id' => $account->id,
            'status' => AccountsReceivable::STATUS_PARTIAL,
            'collected_base_amount' => '80.0000',
            'balance_base_amount' => '120.0000',
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/accounts-receivable/{$account->id}/payments", [
                'payment_currency' => Product::CURRENCY_USD,
                'amount' => 120,
                'cash_register_session_id' => $session->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('accounts_receivables', [
            'tenant_id' => $tenant->id,
            'id' => $account->id,
            'status' => AccountsReceivable::STATUS_PAID,
            'balance_base_amount' => '0.0000',
        ]);
    }

    public function test_accounts_receivable_index_filters_by_customer_status_search_and_due_date(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouseA, $productA] = $this->product($tenant, 'AR-FLT-A');
        [$warehouseB, $productB] = $this->product($tenant, 'AR-FLT-B');
        $customerA = Customer::create(['name' => 'Cliente Caracas', 'document_type' => 'V', 'document_number' => '123']);
        $customerB = Customer::create(['name' => 'Cliente Valencia', 'document_type' => 'V', 'document_number' => '456']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Ventas', ['sales.create', 'accounts_receivable.view']);
        $saleA = $this->confirmedSale($tenant, $user, $warehouseA, $productA, 1, $customerA->id);
        $saleB = $this->confirmedSale($tenant, $user, $warehouseB, $productB, 1, $customerB->id);
        $accountA = AccountsReceivable::query()->where('sale_id', $saleA->id)->firstOrFail();
        $accountB = AccountsReceivable::query()->where('sale_id', $saleB->id)->firstOrFail();

        $accountA->forceFill([
            'document_number' => 'COB-VAL-001',
            'due_date' => '2026-07-20',
        ])->save();
        $accountB->forceFill([
            'document_number' => 'COB-VAL-002',
            'due_date' => '2026-08-20',
        ])->save();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/accounts-receivable?search=COB-VAL-001&status=pending&customer_id={$customerA->id}&due_from=2026-07-01&due_to=2026-07-31&limit=10")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $accountA->id)
            ->assertJsonMissing(['id' => $accountB->id]);
    }

    public function test_ves_collection_uses_rate_snapshot(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product, $rateType] = $this->product($tenant, 'AR-003', true);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Ventas', ['sales.create', 'accounts_receivable.collect']);
        $sale = $this->confirmedSale($tenant, $user, $warehouse, $product, 1);
        $account = AccountsReceivable::query()->where('sale_id', $sale->id)->firstOrFail();
        $session = $this->openCashSession($tenant, $user, $warehouse);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/accounts-receivable/{$account->id}/payments", [
                'payment_currency' => Product::CURRENCY_VES,
                'amount' => 60000,
                'cash_register_session_id' => $session->id,
                'exchange_rate_type_id' => $rateType->id,
                'method' => 'pago movil',
            ])
            ->assertCreated()
            ->assertJsonPath('data.exchange_rate_type_code', 'BCV')
            ->assertJsonPath('data.exchange_rate', '600.000000')
            ->assertJsonPath('data.amount_base', '100.0000')
            ->assertJsonPath('data.amount_local', '60000.0000');

        $this->assertDatabaseHas('accounts_receivables', [
            'tenant_id' => $tenant->id,
            'id' => $account->id,
            'status' => AccountsReceivable::STATUS_PAID,
            'collected_base_amount' => '100.0000',
        ]);

        $this->assertDatabaseHas('cash_register_movements', [
            'tenant_id' => $tenant->id,
            'cash_register_session_id' => $session->id,
            'type' => CashRegisterMovement::TYPE_INFLOW,
            'source_type' => AccountsReceivablePayment::class,
            'amount_base' => '100.0000',
        ]);
    }

    public function test_sales_return_reduces_account_receivable_balance(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, 'AR-004');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Ventas', ['sales.create', 'sales_returns.create', 'accounts_receivable.view']);
        $sale = $this->confirmedSale($tenant, $user, $warehouse, $product, 3);

        app(SalesReturnService::class)->create($user, [
            'sale_id' => $sale->id,
            'items' => [[
                'sale_item_id' => $sale->items->first()->id,
                'quantity' => 1,
            ]],
        ]);

        $this->assertDatabaseHas('accounts_receivables', [
            'tenant_id' => $tenant->id,
            'sale_id' => $sale->id,
            'returned_base_amount' => '100.0000',
            'balance_base_amount' => '200.0000',
        ]);
    }

    public function test_account_receivable_rejects_overcollection(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, 'AR-005');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Ventas', ['sales.create', 'accounts_receivable.collect']);
        $sale = $this->confirmedSale($tenant, $user, $warehouse, $product, 1);
        $account = AccountsReceivable::query()->where('sale_id', $sale->id)->firstOrFail();
        $session = $this->openCashSession($tenant, $user, $warehouse);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/accounts-receivable/{$account->id}/payments", [
                'payment_currency' => Product::CURRENCY_USD,
                'amount' => 101,
                'cash_register_session_id' => $session->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_manual_collection_requires_open_cash_register_session(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, 'AR-CASH');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Ventas', ['sales.create', 'accounts_receivable.collect']);
        $sale = $this->confirmedSale($tenant, $user, $warehouse, $product, 1);
        $account = AccountsReceivable::query()->where('sale_id', $sale->id)->firstOrFail();
        $session = $this->openCashSession($tenant, $user, $warehouse, CashRegisterSession::STATUS_CLOSED);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/accounts-receivable/{$account->id}/payments", [
                'payment_currency' => Product::CURRENCY_USD,
                'amount' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cash_register_session_id']);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/accounts-receivable/{$account->id}/payments", [
                'payment_currency' => Product::CURRENCY_USD,
                'amount' => 10,
                'cash_register_session_id' => $session->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_accounts_receivable_do_not_mix_companies_and_reject_foreign_account(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        [$warehouseA, $productA] = $this->product($tenantA, 'AR-A');
        [$warehouseB, $productB] = $this->product($tenantB, 'AR-B');
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantA, $userA, 'Ventas A', ['sales.create', 'accounts_receivable.view', 'accounts_receivable.collect']);
        $this->grantRole($tenantB, $userB, 'Ventas B', ['sales.create']);
        $saleA = $this->confirmedSale($tenantA, $userA, $warehouseA, $productA, 1);
        $saleB = $this->confirmedSale($tenantB, $userB, $warehouseB, $productB, 1);
        $accountB = AccountsReceivable::withoutGlobalScopes()->where('sale_id', $saleB->id)->firstOrFail();
        $sessionA = $this->openCashSession($tenantA, $userA, $warehouseA);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/accounts-receivable')
            ->assertOk()
            ->assertJsonPath('data.0.sale_id', $saleA->id)
            ->assertJsonMissing(['sale_id' => $saleB->id]);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson("/api/accounts-receivable/{$accountB->id}/payments", [
                'payment_currency' => Product::CURRENCY_USD,
                'amount' => 1,
                'cash_register_session_id' => $sessionA->id,
            ])
            ->assertForbidden();
    }

    public function test_accounts_receivable_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, 'AR-006');
        $creator = $this->userInTenant($tenant);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $creator, 'Ventas', ['sales.create']);
        $sale = $this->confirmedSale($tenant, $creator, $warehouse, $product, 1);
        $account = AccountsReceivable::query()->where('sale_id', $sale->id)->firstOrFail();
        $session = $this->openCashSession($tenant, $user, $warehouse);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/accounts-receivable')
            ->assertForbidden();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/accounts-receivable/{$account->id}/payments", [
                'payment_currency' => Product::CURRENCY_USD,
                'amount' => 1,
                'cash_register_session_id' => $session->id,
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

    private function confirmedSale(Tenant $tenant, User $user, Warehouse $warehouse, Product $product, float $quantity, ?int $customerId = null): Sale
    {
        $this->useTenant($tenant);

        app(InventoryMovementService::class)->purchase($warehouse, $product, 10, 50, $user, "Stock prueba {$product->sku}");

        $sale = app(SaleService::class)->createDraft($user, [[
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]], $customerId);

        return app(SaleService::class)->confirm($sale, $user);
    }

    private function product(Tenant $tenant, string $sku, bool $withRate = false): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal {$sku}", 'code' => "BR-{$sku}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Almacen {$sku}", 'code' => "WH-{$sku}"]);
        $rateType = null;

        if ($withRate) {
            $rateType = ExchangeRateType::create(['code' => 'BCV', 'name' => 'Tasa BCV', 'is_default' => true]);
            ExchangeRate::create([
                'exchange_rate_type_id' => $rateType->id,
                'rate' => 600,
                'effective_at' => '2026-07-02 12:00:00',
                'is_active' => true,
            ]);
        }

        $product = Product::create([
            'name' => "Producto {$sku}",
            'sku' => $sku,
            'tracking_type' => Product::TRACKING_QUANTITY,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        return [$warehouse, $product, $rateType];
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

    private function openCashSession(Tenant $tenant, User $user, Warehouse $warehouse, string $status = CashRegisterSession::STATUS_OPEN): CashRegisterSession
    {
        $this->useTenant($tenant);

        return CashRegisterSession::create([
            'branch_id' => $warehouse->branch_id,
            'cashier_id' => $user->id,
            'opened_by' => $user->id,
            'status' => $status,
            'opening_base_amount' => 0,
            'opening_local_amount' => 0,
            'expected_base_amount' => 0,
            'expected_local_amount' => 0,
            'opened_at' => now(),
            'closed_at' => $status === CashRegisterSession::STATUS_CLOSED ? now() : null,
        ]);
    }
}
