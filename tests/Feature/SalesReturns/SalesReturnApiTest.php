<?php

namespace Tests\Feature\SalesReturns;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
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

class SalesReturnApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_request_approve_and_process_return_then_inventory_increases(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_QUANTITY, 'RET-001');
        StockBalance::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_available' => 5]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor', ['sales.create', 'sales.view', 'sales_returns.create', 'sales_returns.view', 'sales_returns.review', 'sales_returns.process']);
        $sale = $this->confirmedSale($tenant, $user, $warehouse, $product, 2);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales-returns', [
                'sale_id' => $sale->id,
                'reason' => 'Cliente devolvio producto',
                'items' => [[
                    'sale_item_id' => $sale->items->first()->id,
                    'quantity' => 1,
                    'condition' => SalesReturnItem::CONDITION_SELLABLE,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', SalesReturn::STATUS_REQUESTED)
            ->assertJsonPath('data.sale.receivable.balance_base_amount', '200.0000')
            ->assertJsonPath('data.items.0.quantity', '1.0000');

        $this->assertNull($response->json('data.items.0.stock_movement_id'));

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '3.0000',
        ]);
        $this->assertDatabaseMissing('stock_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'sale_return',
            'reference_type' => SalesReturn::class,
        ]);

        $returnId = $response->json('data.id');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/sales-returns/{$returnId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', SalesReturn::STATUS_APPROVED);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/sales-returns/{$returnId}/process", ['refund_mode' => 'none'])
            ->assertOk()
            ->assertJsonPath('data.status', SalesReturn::STATUS_PROCESSED);

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '4.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'sale_return',
            'reference_type' => SalesReturn::class,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('data.sales_returns.0.status', SalesReturn::STATUS_PROCESSED)
            ->assertJsonPath('data.sales_returns.0.items.0.sale_item_id', $sale->items->first()->id);
    }

    public function test_sales_return_cannot_exceed_sold_quantity(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_QUANTITY, 'RET-002');
        StockBalance::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_available' => 3]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor', ['sales.create', 'sales_returns.create']);
        $sale = $this->confirmedSale($tenant, $user, $warehouse, $product, 1);

        $payload = [
            'sale_id' => $sale->id,
            'items' => [[
                'sale_item_id' => $sale->items->first()->id,
                'quantity' => 1,
            ]],
        ];

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)->postJson('/api/sales-returns', $payload)->assertCreated();
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)->postJson('/api/sales-returns', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_serialized_sale_return_restores_product_unit_status(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_SERIALIZED, 'RET-003');
        StockBalance::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_available' => 2]);
        $unit = ProductUnit::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860001111111111',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor', ['products.view', 'sales.create', 'sales_returns.create', 'sales_returns.review', 'sales_returns.process']);
        $sale = $this->confirmedSale($tenant, $user, $warehouse, $product, 1, [$unit->id]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales-returns', [
                'sale_id' => $sale->id,
                'items' => [[
                    'sale_item_id' => $sale->items->first()->id,
                    'quantity' => 1,
                    'product_unit_ids' => [$unit->id],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.product_unit_ids.0', $unit->id);

        $returnId = $response->json('data.id');
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $unit->id,
            'status' => ProductUnit::STATUS_SOLD,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/sales-returns/{$returnId}/approve")
            ->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/sales-returns/{$returnId}/process", ['refund_mode' => 'none'])
            ->assertOk();

        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $unit->id,
            'warehouse_id' => $warehouse->id,
            'status' => ProductUnit::STATUS_AVAILABLE,
            'released_stock_movement_id' => null,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/products/{$product->id}/serials?status=available&warehouse_id={$warehouse->id}")
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $unit->id)
            ->assertJsonPath('data.data.0.status', ProductUnit::STATUS_AVAILABLE)
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_damaged_serialized_sale_return_does_not_become_pos_available_stock(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_SERIALIZED, 'RET-DMG');
        StockBalance::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_available' => 1]);
        $unit = ProductUnit::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860009999999999',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Gerente', ['products.view', 'sales.create', 'sales_returns.create', 'sales_returns.review', 'sales_returns.process']);
        $sale = $this->confirmedSale($tenant, $user, $warehouse, $product, 1, [$unit->id]);

        $created = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales-returns', [
                'sale_id' => $sale->id,
                'items' => [[
                    'sale_item_id' => $sale->items->first()->id,
                    'quantity' => 1,
                    'condition' => SalesReturnItem::CONDITION_DAMAGED,
                    'product_unit_ids' => [$unit->id],
                ]],
            ])
            ->assertCreated();

        $returnId = $created->json('data.id');

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)->postJson("/api/sales-returns/{$returnId}/approve")->assertOk();
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)->postJson("/api/sales-returns/{$returnId}/process", ['refund_mode' => 'none'])->assertOk();

        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_available' => '0.0000',
            'quantity_damaged' => '1.0000',
        ]);
        $this->assertDatabaseHas('product_units', [
            'tenant_id' => $tenant->id,
            'id' => $unit->id,
            'warehouse_id' => $warehouse->id,
            'status' => ProductUnit::STATUS_DAMAGED,
            'released_stock_movement_id' => null,
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/inventory-center/products/{$product->id}/serials?status=available&warehouse_id={$warehouse->id}")
            ->assertOk()
            ->assertJsonPath('data.data', [])
            ->assertJsonPath('data.pagination.total', 0);
    }

    public function test_serialized_sale_return_rejects_unit_not_sold_in_sale_item(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_SERIALIZED, 'RET-005');
        StockBalance::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_available' => 2]);
        $soldUnit = ProductUnit::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860001111111112',
            'status' => ProductUnit::STATUS_AVAILABLE,
        ]);
        $otherUnit = ProductUnit::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
            'serial_number' => '860001111111113',
            'status' => ProductUnit::STATUS_SOLD,
        ]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Vendedor', ['sales.create', 'sales_returns.create']);
        $sale = $this->confirmedSale($tenant, $user, $warehouse, $product, 1, [$soldUnit->id]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales-returns', [
                'sale_id' => $sale->id,
                'items' => [[
                    'sale_item_id' => $sale->items->first()->id,
                    'quantity' => 1,
                    'product_unit_ids' => [$otherUnit->id],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_unit_ids']);
    }

    public function test_sales_returns_do_not_mix_companies_and_reject_foreign_sale(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        [$warehouseA, $productA] = $this->product($tenantA, Product::TRACKING_QUANTITY, 'RET-A');
        [$warehouseB, $productB] = $this->product($tenantB, Product::TRACKING_QUANTITY, 'RET-B');
        $this->useTenant($tenantA);
        StockBalance::create(['warehouse_id' => $warehouseA->id, 'product_id' => $productA->id, 'quantity_available' => 2]);
        $this->useTenant($tenantB);
        StockBalance::create(['warehouse_id' => $warehouseB->id, 'product_id' => $productB->id, 'quantity_available' => 2]);
        $userA = $this->userInTenant($tenantA);
        $userB = $this->userInTenant($tenantB);
        $this->grantRole($tenantA, $userA, 'Vendedor A', ['sales.create', 'sales_returns.create', 'sales_returns.view']);
        $this->grantRole($tenantB, $userB, 'Vendedor B', ['sales.create']);
        $saleB = $this->confirmedSale($tenantB, $userB, $warehouseB, $productB, 1);

        $this
            ->actingAs($userA)
            ->withHeader('X-Tenant', $tenantA->slug)
            ->postJson('/api/sales-returns', [
                'sale_id' => $saleB->id,
                'items' => [[
                    'sale_item_id' => $saleB->items->first()->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sale_id']);
    }

    public function test_sales_return_api_rejects_user_without_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_QUANTITY, 'RET-004');
        StockBalance::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_available' => 2]);
        $creator = $this->userInTenant($tenant);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $creator, 'Vendedor', ['sales.create']);
        $sale = $this->confirmedSale($tenant, $creator, $warehouse, $product, 1);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales-returns', [
                'sale_id' => $sale->id,
                'items' => [[
                    'sale_item_id' => $sale->items->first()->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertForbidden();
    }

    public function test_approved_sales_return_can_refund_from_current_cash_session(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        [$warehouse, $product] = $this->product($tenant, Product::TRACKING_QUANTITY, 'RET-CASH');
        StockBalance::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_available' => 5]);
        $user = $this->userInTenant($tenant);
        $this->grantRole($tenant, $user, 'Gerente', [
            'sales.create',
            'sales_returns.create',
            'sales_returns.review',
            'sales_returns.process',
            'sales_returns.refund',
            'cash_register.move',
        ]);
        $sale = $this->confirmedSale($tenant, $user, $warehouse, $product, 1);
        $cashRegister = CashRegister::create([
            'branch_id' => $warehouse->branch_id,
            'name' => 'Mostrador',
            'code' => 'MOST-RET',
            'status' => CashRegister::STATUS_ACTIVE,
        ]);
        $session = CashRegisterSession::create([
            'branch_id' => $warehouse->branch_id,
            'cash_register_id' => $cashRegister->id,
            'cashier_id' => $user->id,
            'opened_by' => $user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opening_base_amount' => 0,
            'opening_local_amount' => 0,
            'expected_base_amount' => 0,
            'expected_local_amount' => 0,
            'opened_at' => now(),
        ]);

        $created = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/sales-returns', [
                'sale_id' => $sale->id,
                'items' => [[
                    'sale_item_id' => $sale->items->first()->id,
                    'quantity' => 1,
                    'condition' => SalesReturnItem::CONDITION_SELLABLE,
                ]],
            ])
            ->assertCreated();

        $returnId = $created->json('data.id');

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->slug)->postJson("/api/sales-returns/{$returnId}/approve")->assertOk();

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/sales-returns/{$returnId}/process", [
                'refund_mode' => 'cash',
                'refund_currency' => 'USD',
                'refund_amount' => 100,
                'refund_method' => CashRegisterMovement::METHOD_CASH,
                'refund_cash_register_session_id' => $session->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', SalesReturn::STATUS_PROCESSED)
            ->assertJsonPath('data.refund_amount_base', 100);

        $this->assertDatabaseHas('cash_register_movements', [
            'tenant_id' => $tenant->id,
            'cash_register_session_id' => $session->id,
            'type' => CashRegisterMovement::TYPE_OUTFLOW,
            'source_type' => SalesReturn::class,
            'source_id' => $returnId,
            'amount_base' => '100.0000',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function confirmedSale(Tenant $tenant, User $user, Warehouse $warehouse, Product $product, float $quantity, array $productUnitIds = []): Sale
    {
        $this->useTenant($tenant);

        $sale = app(SaleService::class)->createDraft($user, [[
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'product_unit_ids' => $productUnitIds,
        ]]);

        return app(SaleService::class)->confirm($sale, $user);
    }

    private function product(Tenant $tenant, string $trackingType, string $sku): array
    {
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => "Sucursal {$sku}", 'code' => "BR-{$sku}"]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => "Almacen {$sku}", 'code' => "WH-{$sku}"]);
        $rateType = ExchangeRateType::create(['code' => "BCV-{$sku}", 'name' => "Tasa {$sku}", 'is_default' => true]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'rate' => 500,
            'effective_at' => '2026-07-02 12:00:00',
            'is_active' => true,
        ]);
        $product = Product::create([
            'name' => "Producto {$sku}",
            'sku' => $sku,
            'tracking_type' => $trackingType,
            'base_price' => 100,
            'sale_currency' => Product::CURRENCY_USD,
        ]);

        return [$warehouse, $product];
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
