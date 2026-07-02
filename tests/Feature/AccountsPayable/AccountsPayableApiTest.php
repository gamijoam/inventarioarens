<?php

namespace Tests\Feature\AccountsPayable;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Products\Models\Product;
use App\Modules\PurchaseReturns\Services\PurchaseReturnService;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Services\PurchaseOrderService;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AccountsPayableApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_received_purchase_creates_pending_account_payable(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, 'AP-001');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Compras', ['purchases.create', 'purchases.approve', 'accounts_payable.view']);

        $purchase = $this->receivedPurchase($tenant, $user, $warehouse, $product, 5, 10);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/accounts-payable')
            ->assertOk()
            ->assertJsonPath('data.0.purchase_order_id', $purchase->id)
            ->assertJsonPath('data.0.status', AccountsPayable::STATUS_PENDING)
            ->assertJsonPath('data.0.original_base_amount', '50.0000')
            ->assertJsonPath('data.0.balance_base_amount', '50.0000');
    }

    public function test_user_can_register_partial_and_total_usd_payments(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, 'AP-002');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Compras', ['purchases.create', 'purchases.approve', 'accounts_payable.view', 'accounts_payable.pay']);
        $purchase = $this->receivedPurchase($tenant, $user, $warehouse, $product, 5, 10);
        $account = AccountsPayable::query()->where('purchase_order_id', $purchase->id)->firstOrFail();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/accounts-payable/{$account->id}/payments", [
                'payment_currency' => PurchaseOrder::CURRENCY_USD,
                'amount' => 20,
                'method' => 'transferencia',
                'reference' => 'PAGO-001',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount_base', '20.0000');

        $this->assertDatabaseHas('accounts_payables', [
            'tenant_id' => $tenant->id,
            'id' => $account->id,
            'status' => AccountsPayable::STATUS_PARTIAL,
            'paid_base_amount' => '20.0000',
            'balance_base_amount' => '30.0000',
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/accounts-payable/{$account->id}/payments", [
                'payment_currency' => PurchaseOrder::CURRENCY_USD,
                'amount' => 30,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('accounts_payables', [
            'tenant_id' => $tenant->id,
            'id' => $account->id,
            'status' => AccountsPayable::STATUS_PAID,
            'balance_base_amount' => '0.0000',
        ]);
    }

    public function test_ves_payment_uses_rate_snapshot(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product, $rateType] = $this->product($tenant, 'AP-003', true);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Compras', ['purchases.create', 'purchases.approve', 'accounts_payable.pay']);
        $purchase = $this->receivedPurchase($tenant, $user, $warehouse, $product, 2, 50);
        $account = AccountsPayable::query()->where('purchase_order_id', $purchase->id)->firstOrFail();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/accounts-payable/{$account->id}/payments", [
                'payment_currency' => PurchaseOrder::CURRENCY_VES,
                'amount' => 60000,
                'exchange_rate_type_id' => $rateType->id,
                'method' => 'pago movil',
            ])
            ->assertCreated()
            ->assertJsonPath('data.exchange_rate_type_code', 'BCV')
            ->assertJsonPath('data.exchange_rate', '600.000000')
            ->assertJsonPath('data.amount_base', '100.0000')
            ->assertJsonPath('data.amount_local', '60000.0000');

        $this->assertDatabaseHas('accounts_payables', [
            'tenant_id' => $tenant->id,
            'id' => $account->id,
            'status' => AccountsPayable::STATUS_PAID,
            'paid_base_amount' => '100.0000',
        ]);
    }

    public function test_purchase_return_reduces_account_payable_balance(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, 'AP-004');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Compras', ['purchases.create', 'purchases.approve', 'purchase_returns.create', 'accounts_payable.view']);
        $purchase = $this->receivedPurchase($tenant, $user, $warehouse, $product, 5, 10);

        app(PurchaseReturnService::class)->create($user, [
            'purchase_order_id' => $purchase->id,
            'items' => [[
                'purchase_item_id' => $purchase->items->first()->id,
                'quantity' => 2,
            ]],
        ]);

        $this->assertDatabaseHas('accounts_payables', [
            'tenant_id' => $tenant->id,
            'purchase_order_id' => $purchase->id,
            'returned_base_amount' => '20.0000',
            'balance_base_amount' => '30.0000',
        ]);
    }

    public function test_account_payable_rejects_overpayment(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, 'AP-005');
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Compras', ['purchases.create', 'purchases.approve', 'accounts_payable.pay']);
        $purchase = $this->receivedPurchase($tenant, $user, $warehouse, $product, 1, 10);
        $account = AccountsPayable::query()->where('purchase_order_id', $purchase->id)->firstOrFail();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/accounts-payable/{$account->id}/payments", [
                'payment_currency' => PurchaseOrder::CURRENCY_USD,
                'amount' => 11,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_accounts_payable_do_not_mix_companies_and_reject_foreign_account(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        [$warehouseA, $productA] = $this->product($tenantA, 'AP-A');
        [$warehouseB, $productB] = $this->product($tenantB, 'AP-B');
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantA, $userA, 'Compras A', ['purchases.create', 'purchases.approve', 'accounts_payable.view', 'accounts_payable.pay']);
        $this->grantRole($tenantB, $userB, 'Compras B', ['purchases.create', 'purchases.approve']);
        $purchaseA = $this->receivedPurchase($tenantA, $userA, $warehouseA, $productA, 1, 10);
        $purchaseB = $this->receivedPurchase($tenantB, $userB, $warehouseB, $productB, 1, 20);
        $accountB = AccountsPayable::withoutGlobalScopes()->where('purchase_order_id', $purchaseB->id)->firstOrFail();

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->getJson('/api/accounts-payable')
            ->assertOk()
            ->assertJsonPath('data.0.purchase_order_id', $purchaseA->id)
            ->assertJsonMissing(['purchase_order_id' => $purchaseB->id]);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson("/api/accounts-payable/{$accountB->id}/payments", [
                'payment_currency' => PurchaseOrder::CURRENCY_USD,
                'amount' => 1,
            ])
            ->assertForbidden();
    }

    public function test_accounts_payable_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, 'AP-006');
        $creator = $this->userInTenant($tenant);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $creator, 'Compras', ['purchases.create', 'purchases.approve']);
        $purchase = $this->receivedPurchase($tenant, $creator, $warehouse, $product, 1, 10);
        $account = AccountsPayable::query()->where('purchase_order_id', $purchase->id)->firstOrFail();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/accounts-payable')
            ->assertForbidden();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/accounts-payable/{$account->id}/payments", [
                'payment_currency' => PurchaseOrder::CURRENCY_USD,
                'amount' => 1,
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

    private function receivedPurchase(
        Tenant $tenant,
        User $user,
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        float $unitCost,
    ): PurchaseOrder {
        $this->useTenant($tenant);
        $supplier = Supplier::create([
            'name' => "Proveedor {$product->sku}",
            'document_type' => Supplier::DOCUMENT_J,
            'document_number' => "J-{$product->sku}",
        ]);

        $purchase = app(PurchaseOrderService::class)->createDraft($user, [
            'supplier_id' => $supplier->id,
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'items' => [[
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ]],
        ]);

        return app(PurchaseOrderService::class)->receive($purchase, $user);
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
}
