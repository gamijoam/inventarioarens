<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Inventory\Services\InventoryReconciliationService;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Una reserva vencida solo libera su cantidad abierta: lo ya liberado o
 * despachado contra la misma referencia se descuenta y nunca se toma el
 * reservado de otra orden (auditoria 2026-11-11, critico C5).
 */
class InventoryReservationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_expiring_a_dispatched_reservation_does_not_steal_other_reservations(): void
    {
        [$tenant, $w, $p] = $this->stockContext('res-a');
        $inv = app(InventoryMovementService::class);
        $inv->purchase($w, $p, 10, 5.0);
        $inv->reserve($w, $p, 2, referenceType: 'App\\Pedido', referenceId: 1, expiresAt: now()->subMinute());
        $inv->dispatchReservedTransfer($w, $p, 2, referenceType: 'App\\Pedido', referenceId: 1);
        $inv->reserve($w, $p, 3, referenceType: PosOrder::class, referenceId: 9, expiresAt: now()->addHour());

        $this->artisan('inventory:expire-reservations', ['tenant' => $tenant->slug])->assertSuccessful();

        $this->assertBalance($w, $p, 5, 3);
        $this->assertSame(0, StockMovement::query()->where('type', 'released')->count());
        $this->assertSame([], app(InventoryReconciliationService::class)->reconcileTenant($tenant->id)['drifts']);
    }

    public function test_transfer_reservations_are_not_expired_by_the_job(): void
    {
        [$tenant, $w, $p] = $this->stockContext('res-b');
        $inv = app(InventoryMovementService::class);
        $inv->purchase($w, $p, 10, 5.0);
        $inv->reserve($w, $p, 4, referenceType: InventoryTransfer::class, referenceId: 3, expiresAt: now()->subDay());

        $this->artisan('inventory:expire-reservations', ['tenant' => $tenant->slug])->assertSuccessful();

        $this->assertBalance($w, $p, 6, 4);
        $this->assertSame(0, StockMovement::query()->where('type', 'released')->count(), 'Un traslado en preparacion mantiene su reserva hasta despacharse o cancelarse.');
    }

    public function test_reservation_without_expiry_is_stored_without_expiry(): void
    {
        [, $w, $p] = $this->stockContext('res-c');
        $inv = app(InventoryMovementService::class);
        $inv->purchase($w, $p, 10, 5.0);

        $movement = $inv->reserve($w, $p, 1, referenceType: InventoryTransfer::class, referenceId: 1, expires: false);

        $this->assertNull($movement->reservation_expires_at);
    }

    public function test_expired_unconsumed_reservation_is_released_exactly_once(): void
    {
        [$tenant, $w, $p] = $this->stockContext('res-d');
        $inv = app(InventoryMovementService::class);
        $inv->purchase($w, $p, 10, 5.0);
        $inv->reserve($w, $p, 2, referenceType: PosOrder::class, referenceId: 1, expiresAt: now()->subMinute());

        $this->artisan('inventory:expire-reservations', ['tenant' => $tenant->slug])->assertSuccessful();
        $this->artisan('inventory:expire-reservations', ['tenant' => $tenant->slug])->assertSuccessful();

        $this->assertBalance($w, $p, 10, 0);
        $this->assertSame(1, StockMovement::query()->where('type', 'released')->count());
        $this->assertSame([], app(InventoryReconciliationService::class)->reconcileTenant($tenant->id)['drifts']);
    }

    public function test_partially_released_reservation_only_releases_the_rest(): void
    {
        [$tenant, $w, $p] = $this->stockContext('res-e');
        $inv = app(InventoryMovementService::class);
        $inv->purchase($w, $p, 10, 5.0);
        $inv->reserve($w, $p, 3, referenceType: PosOrder::class, referenceId: 1, expiresAt: now()->subMinute());
        $inv->release($w, $p, 1, referenceType: PosOrder::class, referenceId: 1);
        $inv->reserve($w, $p, 4, referenceType: PosOrder::class, referenceId: 2, expiresAt: now()->addHour());

        $this->artisan('inventory:expire-reservations', ['tenant' => $tenant->slug])->assertSuccessful();

        $this->assertBalance($w, $p, 6, 4);
        $this->assertEqualsWithDelta(2.0, (float) StockMovement::query()->where('reference_type', 'inventory_reservation_expired')->sum('quantity'), 0.0001);
    }

    public function test_job_continues_with_other_tenants_when_one_reservation_cannot_be_released(): void
    {
        [$broken, $wb, $pb] = $this->stockContext('res-f1');
        $inv = app(InventoryMovementService::class);
        $inv->purchase($wb, $pb, 10, 5.0);
        $inv->reserve($wb, $pb, 2, referenceType: PosOrder::class, referenceId: 1, expiresAt: now()->subMinute());
        DB::table('stock_balances')->where('tenant_id', $broken->id)->update(['quantity_reserved' => 0]);

        [$healthy, $wh, $ph] = $this->stockContext('res-f2');
        $inv->purchase($wh, $ph, 10, 5.0);
        $inv->reserve($wh, $ph, 2, referenceType: PosOrder::class, referenceId: 1, expiresAt: now()->subMinute());

        $this->artisan('inventory:expire-reservations')->assertFailed();

        app(TenantManager::class)->set($healthy);
        $this->assertBalance($wh, $ph, 10, 0);
    }

    public function test_pos_payment_of_expired_held_order_does_not_release_twice(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $this->stockFromPurchase($ctx, 50);
        $sid = $this->openSession($ctx);
        $first = $this->hold($ctx, 2);
        StockMovement::query()->where('reference_type', PosOrder::class)->where('reference_id', $first)->update(['reservation_expires_at' => now()->subMinute()]);
        $this->artisan('inventory:expire-reservations', ['tenant' => $ctx['tenant']->slug])->assertSuccessful();
        $this->hold($ctx, 3);
        $this->assertBalance($ctx['warehouse'], $ctx['product'], 47, 3);

        $this->pay($ctx, $first, $sid, 200)->assertOk();

        $this->assertBalance($ctx['warehouse'], $ctx['product'], 45, 3, 'La orden en espera conserva su reserva de 3 y la venta sale del disponible.');
        $this->useTenant($ctx['tenant']);
        $this->assertSame([], app(InventoryReconciliationService::class)->reconcileTenant($ctx['tenant']->id)['drifts']);
    }

    public function test_pos_payment_of_held_order_with_valid_reservation_still_consumes_it(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $this->stockFromPurchase($ctx, 50);
        $sid = $this->openSession($ctx);
        $order = $this->hold($ctx, 2);
        $this->assertBalance($ctx['warehouse'], $ctx['product'], 48, 2);

        $this->pay($ctx, $order, $sid, 200)->assertOk();

        $this->assertBalance($ctx['warehouse'], $ctx['product'], 48, 0);
        $this->useTenant($ctx['tenant']);
        $this->assertSame([], app(InventoryReconciliationService::class)->reconcileTenant($ctx['tenant']->id)['drifts']);
    }

    public function test_open_reserved_quantity_helper_applies_consumption_fifo(): void
    {
        [, $w, $p] = $this->stockContext('res-g');
        $inv = app(InventoryMovementService::class);
        $inv->purchase($w, $p, 20, 5.0);
        $first = $inv->reserve($w, $p, 3, referenceType: 'App\\Pedido', referenceId: 1);
        $second = $inv->reserve($w, $p, 4, referenceType: 'App\\Pedido', referenceId: 1);
        $inv->release($w, $p, 2, referenceType: 'App\\Pedido', referenceId: 1);
        $inv->dispatchReservedTransfer($w, $p, 2, referenceType: 'App\\Pedido', referenceId: 1);

        $this->assertEqualsWithDelta(3.0, $inv->openReservedQuantity('App\\Pedido', 1, $w->id, $p->id, null), 0.0001);
        $this->assertEqualsWithDelta(0.0, $inv->openQuantityForReservation($first), 0.0001);
        $this->assertEqualsWithDelta(3.0, $inv->openQuantityForReservation($second), 0.0001);
    }

    private function hold(array $ctx, float $quantity): int
    {
        return (int) $this->actingAs($ctx['cashier'])->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson('/api/pos/orders', ['items' => [['warehouse_id' => $ctx['warehouse']->id, 'product_id' => $ctx['product']->id, 'quantity' => $quantity]]])
            ->assertCreated()->json('data.id');
    }

    private function pay(array $ctx, int $orderId, int $sessionId, float $amount): TestResponse
    {
        return $this->actingAs($ctx['cashier'])->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson("/api/pos/orders/{$orderId}/payments", [
                'cash_register_session_id' => $sessionId,
                'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => $amount]],
            ]);
    }

    private function stockFromPurchase(array $ctx, float $quantity): void
    {
        // El stock inicial entra por kardex para que la conciliacion sea exacta.
        $this->useTenant($ctx['tenant']);
        DB::table('stock_balances')->where('tenant_id', $ctx['tenant']->id)->update(['quantity_available' => 0]);
        app(InventoryMovementService::class)->purchase($ctx['warehouse'], $ctx['product'], $quantity, 5.0);
    }

    private function stockContext(string $slug): array
    {
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug]);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $branch = Branch::create(['name' => "B {$slug}", 'code' => strtoupper($slug)]);
        $w = Warehouse::create(['branch_id' => $branch->id, 'name' => "W {$slug}", 'code' => strtoupper($slug)]);
        $p = Product::create(['name' => "P {$slug}", 'sku' => strtoupper($slug)]);

        return [$tenant, $w, $p];
    }

    private function assertBalance(Warehouse $w, Product $p, float $available, float $reserved, string $message = ''): void
    {
        $balance = StockBalance::withoutGlobalScopes()->where('warehouse_id', $w->id)->where('product_id', $p->id)->firstOrFail();
        $this->assertEqualsWithDelta($available, (float) $balance->quantity_available, 0.0001, $message);
        $this->assertEqualsWithDelta($reserved, (float) $balance->quantity_reserved, 0.0001, $message);
    }

    /**
     * @return array{tenant: Tenant, branch: Branch, warehouse: Warehouse, cashier: User, product: Product, rateType: ExchangeRateType, cashRegister: CashRegister}
     */
    private function bootContext(string $saleCurrency, float $rate): array
    {
        $tenant = Tenant::create(['name' => 'Caja contable', 'slug' => 'caja-contable-'.uniqid()]);
        $this->useTenant($tenant);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'MAIN-'.uniqid()]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'WH-'.uniqid()]);
        $cashRegister = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja 1', 'code' => 'C1-'.uniqid()]);
        $rateType = ExchangeRateType::create(['name' => 'BCV', 'code' => 'BCV', 'is_default' => true]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $rateType->id,
            'rate' => $rate,
            'effective_at' => now()->subMinute(),
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
        $role = Role::findOrCreate('Administrador', 'web');
        $role->syncPermissions(BasePermissions::PERMISSIONS);
        $cashier->assignRole($role);

        return compact('tenant', 'branch', 'warehouse', 'cashier', 'product', 'rateType', 'cashRegister');
    }

    private function openSession(array $ctx, float $openingUsd = 0): int
    {
        return (int) $this->actingAs($ctx['cashier'])
            ->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson('/api/cash-register/sessions', [
                'branch_id' => $ctx['branch']->id,
                'cash_register_id' => $ctx['cashRegister']->id,
                'opening_base_amount' => $openingUsd,
                'opening_local_amount' => 0,
                'exchange_rate_type_id' => $ctx['rateType']->id,
            ])
            ->assertCreated()
            ->json('data.id');
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
