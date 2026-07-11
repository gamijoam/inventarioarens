<?php

namespace Tests\Feature\AccountsReceivable;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use App\Modules\SalesReturns\Models\SalesReturn;
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

class AccountsReceivableReturnDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_on_discounted_item_uses_discounted_unit_price_not_list_price(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda CxC', 'slug' => 'tienda-cxc']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'CxC', [
            'sales.view', 'sales.create', 'sales.cancel',
            'sales_returns.view', 'sales_returns.create',
            'accounts_receivable.view', 'accounts_receivable.collect',
        ]);

        [$warehouse, $product] = $this->product($tenant, 'CXC-DISC-001');

        $sale = $this->confirmedSaleWithDiscount($tenant, $user, $warehouse, $product, quantity: 2, discountType: 'fixed', discountValue: 50);
        $saleItem = $sale->items()->firstOrFail();

        $this->assertSame('100.0000', (string) $saleItem->base_unit_price, 'Precio lista debe ser 100');
        $this->assertSame('150.0000', (string) $saleItem->base_total_amount, 'Tras descuento 50, total = 150');

        $salesReturn = $this->createReturn($tenant, $user, $sale, $saleItem, quantity: 1);

        $account = AccountsReceivable::query()->where('sale_id', $sale->id)->firstOrFail();
        $account->refresh();

        $this->assertSame(
            '75.0000',
            (string) $account->returned_base_amount,
            'returned_base_amount debe ser 75 (= 150/2 unidades) NO 100 (precio lista)'
        );
        $this->assertSame(
            '75.0000',
            (string) $account->balance_base_amount,
            'balance_base debe ser 150 - 75 = 75 (NO 100, que daria 50 fantasma)'
        );
    }

    public function test_full_return_on_discounted_item_returns_full_discounted_total(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda CxC 2', 'slug' => 'tienda-cxc-2']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'CxC', [
            'sales.view', 'sales.create', 'sales.cancel',
            'sales_returns.view', 'sales_returns.create',
            'accounts_receivable.view', 'accounts_receivable.collect',
        ]);

        [$warehouse, $product] = $this->product($tenant, 'CXC-DISC-002');

        $sale = $this->confirmedSaleWithDiscount($tenant, $user, $warehouse, $product, quantity: 3, discountType: 'percent', discountValue: 50);
        $saleItem = $sale->items()->firstOrFail();

        $this->assertSame('150.0000', (string) $saleItem->base_total_amount, '50% descuento en 3 unidades a 100 = 150');

        $this->createReturn($tenant, $user, $sale, $saleItem, quantity: 3);

        $account = AccountsReceivable::query()->where('sale_id', $sale->id)->firstOrFail();
        $account->refresh();

        $this->assertSame(
            '150.0000',
            (string) $account->returned_base_amount,
            'Full return de 3 unidades a precio descontado = 150 total (NO 300 de lista)'
        );
        $this->assertSame(
            '0.0000',
            (string) $account->balance_base_amount
        );
        $this->assertSame(AccountsReceivable::STATUS_PAID, $account->status);
    }

    public function test_return_in_ves_uses_discounted_unit_price(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda CxC 3', 'slug' => 'tienda-cxc-3']);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'CxC', [
            'sales.view', 'sales.create', 'sales.cancel',
            'sales_returns.view', 'sales_returns.create',
            'accounts_receivable.view', 'accounts_receivable.collect',
        ]);

        [$warehouse, $product, $rateType] = $this->product($tenant, 'CXC-DISC-003', withRate: true);

        $this->useTenant($tenant);

        app(InventoryMovementService::class)->purchase($warehouse, $product, 10, 50, $user, 'Stock VES con descuento');

        $sale = app(SaleService::class)->createDraft($user, [[
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'discount_type' => 'fixed',
            'discount_value' => 20000,
            'discount_reason' => 'Promo cliente',
        ]]);

        $sale = app(SaleService::class)->confirm($sale, $user);
        $saleItem = $sale->items()->firstOrFail();

        $this->createReturn($tenant, $user, $sale, $saleItem, quantity: 1);

        $account = AccountsReceivable::query()->where('sale_id', $sale->id)->firstOrFail();
        $account->refresh();

        $this->assertGreaterThan(
            0.0,
            (float) $account->returned_local_amount,
            'returned_local_amount debe ser > 0'
        );

        $perUnitBase = (float) $saleItem->base_total_amount / (float) $saleItem->quantity;
        $expectedReturnedBase = round($perUnitBase * 1.0, 4);
        $this->assertSame(
            number_format((float) $account->returned_base_amount, 4, '.', ''),
            number_format($expectedReturnedBase, 4, '.', ''),
            'returned_base_amount debe calcularse con precio descontado, no lista'
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
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
            'sale_currency' => $withRate ? Product::CURRENCY_VES : Product::CURRENCY_USD,
        ]);

        return [$warehouse, $product, $rateType];
    }

    private function confirmedSaleWithDiscount(Tenant $tenant, User $user, Warehouse $warehouse, Product $product, float $quantity, string $discountType, float $discountValue): Sale
    {
        $this->useTenant($tenant);

        app(InventoryMovementService::class)->purchase($warehouse, $product, 10, 50, $user, "Stock prueba {$product->sku}");

        $sale = app(SaleService::class)->createDraft($user, [[
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_reason' => 'Promocion test',
        ]]);

        return app(SaleService::class)->confirm($sale, $user);
    }

    private function createReturn(Tenant $tenant, User $user, Sale $sale, $saleItem, float $quantity): SalesReturn
    {
        $this->useTenant($tenant);

        return app(SalesReturnService::class)->create($user, [
            'sale_id' => $sale->id,
            'reason' => 'Devolucion test',
            'items' => [
                [
                    'sale_item_id' => $saleItem->id,
                    'quantity' => $quantity,
                    'condition' => 'sellable',
                    'reason' => 'Test',
                ],
            ],
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
