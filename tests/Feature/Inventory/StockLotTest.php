<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Services\PurchaseOrderService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use App\Modules\SalesReturns\Services\SalesReturnService;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Vencimientos y lotes para perecederos: la fecha se exige al recibir la
 * compra, las salidas consumen solas lo que vence antes (los vencidos al
 * final) y los lotes por vencer se consultan y se dan de baja valorados.
 */
class StockLotTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Warehouse $warehouse;

    private Warehouse $secondWarehouse;

    private Product $product;

    private Supplier $supplier;

    public function test_receiving_a_perishable_purchase_requires_the_expiration_date(): void
    {
        $purchase = $this->draft(5, 2);

        try {
            app(PurchaseOrderService::class)->receive($purchase, $this->user, ['items' => [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => 5]]]);
            $this->fail('Se esperaba exigir la fecha de vencimiento.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.expires_on', $exception->errors());
        }

        $this->assertSame(0, StockLot::query()->count());
        $this->assertSame(0.0, $this->available());
    }

    public function test_receipt_creates_the_lot_with_its_expiration_and_cost(): void
    {
        $this->receive(5, 2, now()->addDays(30)->toDateString(), 'L-001');

        $lot = StockLot::query()->sole();
        $this->assertSame('L-001', $lot->lot_code);
        $this->assertSame(now()->addDays(30)->toDateString(), $lot->expires_on->toDateString());
        $this->assertEqualsWithDelta(5.0, (float) $lot->quantity, 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $lot->unit_cost, 0.0001);
        $this->assertSame($this->warehouse->id, $lot->warehouse_id);
    }

    public function test_products_without_expiration_control_do_not_create_lots(): void
    {
        $this->product->update(['tracks_expiration' => false]);

        $this->receive(5, 2);

        $this->assertSame(0, StockLot::query()->count());
        $this->assertSame(5.0, $this->available());
    }

    public function test_sales_consume_first_what_expires_first(): void
    {
        $later = $this->receive(5, 2, now()->addDays(30)->toDateString(), 'TARDE');
        $sooner = $this->receive(5, 2, now()->addDays(10)->toDateString(), 'PRONTO');

        $this->sell(7);

        $this->assertEqualsWithDelta(0.0, (float) $this->lot('PRONTO')->quantity, 0.0001);
        $this->assertEqualsWithDelta(3.0, (float) $this->lot('TARDE')->quantity, 0.0001);
        $sale = StockMovement::query()->where('type', 'sale')->sole();
        $this->assertSame(
            [['PRONTO', -5.0], ['TARDE', -2.0]],
            DB::table('stock_lot_movements')->join('stock_lots', 'stock_lots.id', '=', 'stock_lot_movements.stock_lot_id')
                ->where('stock_movement_id', $sale->id)->orderBy('stock_lots.expires_on')
                ->get(['lot_code', 'stock_lot_movements.quantity'])
                ->map(fn ($row): array => [$row->lot_code, (float) $row->quantity])->all(),
        );
    }

    public function test_expired_lots_are_consumed_last(): void
    {
        $this->receive(2, 2, now()->subDay()->toDateString(), 'VENCIDO');
        $this->receive(5, 2, now()->addDays(20)->toDateString(), 'VIGENTE');

        $this->sell(3);

        $this->assertEqualsWithDelta(2.0, (float) $this->lot('VENCIDO')->quantity, 0.0001, 'No se vende lo vencido mientras haya vigente.');
        $this->assertEqualsWithDelta(2.0, (float) $this->lot('VIGENTE')->quantity, 0.0001);
    }

    public function test_stock_from_before_lot_control_is_sold_first(): void
    {
        app(InventoryMovementService::class)->adjustmentIn($this->warehouse, $this->product, 2, $this->user, 'Inventario inicial');
        $this->receive(5, 2, now()->addDays(20)->toDateString(), 'NUEVO');

        $this->sell(3);

        $this->assertEqualsWithDelta(4.0, (float) $this->lot('NUEVO')->quantity, 0.0001, 'Primero sale la existencia anterior sin lote.');
        $this->assertSame(4.0, $this->available());
    }

    public function test_sales_return_puts_the_units_back_in_the_lot_they_came_from(): void
    {
        $this->receive(5, 2, now()->addDays(10)->toDateString(), 'PRONTO');
        $this->receive(5, 2, now()->addDays(30)->toDateString(), 'TARDE');
        $sale = $this->sell(2);

        $service = app(SalesReturnService::class);
        $return = $service->create($this->user, ['sale_id' => $sale->id, 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]]]);
        $service->approve($return, $this->user);
        $service->process($return, $this->user, ['refund_mode' => 'none']);

        $this->assertEqualsWithDelta(4.0, (float) $this->lot('PRONTO')->quantity, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $this->lot('TARDE')->quantity, 0.0001);
    }

    public function test_transfer_moves_the_lots_with_their_dates(): void
    {
        $this->receive(5, 2, now()->addDays(10)->toDateString(), 'PRONTO');
        $this->receive(5, 2, now()->addDays(30)->toDateString(), 'TARDE');

        app(InventoryMovementService::class)->transfer($this->warehouse, $this->secondWarehouse, $this->product->refresh(), 6, $this->user, 'Reposicion');

        $destination = StockLot::query()->where('warehouse_id', $this->secondWarehouse->id)->orderBy('expires_on')->get();
        $this->assertSame(['PRONTO', 'TARDE'], $destination->pluck('lot_code')->all());
        $this->assertSame([5.0, 1.0], $destination->map(fn (StockLot $lot): float => (float) $lot->quantity)->all());
        $this->assertSame(now()->addDays(30)->toDateString(), $destination[1]->expires_on->toDateString());
        $this->assertEqualsWithDelta(4.0, (float) $this->lot('TARDE')->quantity, 0.0001);
    }

    public function test_expiring_lots_are_listed_with_days_left_and_value(): void
    {
        $this->receive(4, 3, now()->addDays(5)->toDateString(), 'PRONTO');
        $this->receive(5, 2, now()->addDays(60)->toDateString(), 'LEJOS');
        $this->receive(2, 2, now()->subDays(2)->toDateString(), 'VENCIDO');

        $response = $this->getJson('/api/stock-lots?status=expiring&days=7')->assertOk();
        $this->assertSame(['PRONTO'], array_column($response->json('data'), 'lot_code'));
        $response->assertJsonPath('data.0.days_left', 5)
            ->assertJsonPath('data.0.quantity', 4)
            ->assertJsonPath('data.0.value_base_amount', 12)
            ->assertJsonPath('data.0.product.name', 'Leche')
            ->assertJsonPath('data.0.warehouse.name', 'Almacen');

        $this->getJson('/api/stock-lots?status=expired')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lot_code', 'VENCIDO')
            ->assertJsonPath('data.0.days_left', -2)
            ->assertJsonPath('summary.expired_value_base_amount', 4);
    }

    public function test_an_expired_lot_can_be_written_off_as_a_valued_loss(): void
    {
        $this->receive(2, 3, now()->subDay()->toDateString(), 'VENCIDO');
        $this->receive(5, 2, now()->addDays(20)->toDateString(), 'VIGENTE');
        $lot = $this->lot('VENCIDO');

        $this->postJson("/api/stock-lots/{$lot->id}/write-off", ['reason' => 'Vencido'])->assertOk();

        $this->assertEqualsWithDelta(0.0, (float) $lot->refresh()->quantity, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $this->lot('VIGENTE')->quantity, 0.0001, 'Se da de baja ese lote, no el que vence antes.');
        $this->assertSame(5.0, $this->available());
        $movement = StockMovement::query()->where('type', 'adjustment_out')->sole();
        $this->assertEqualsWithDelta(2.0, (float) $movement->quantity, 0.0001);
        $this->assertEqualsWithDelta(3.0, (float) $movement->unit_cost, 0.0001, 'La merma se valora al costo del lote.');
        $this->assertStringContainsString('Vencido', (string) $movement->reason);
        $this->assertTrue(DB::table('sync_outbox')->where('event_type', 'stock_movement.created')->where('aggregate_id', $movement->id)->exists());
    }

    public function test_write_off_takes_from_the_chosen_lot_even_if_it_is_not_the_first(): void
    {
        $this->receive(4, 2, now()->addDays(3)->toDateString(), 'PRIMERO');
        $this->receive(3, 5, now()->addDays(40)->toDateString(), 'ROTO');

        $this->postJson('/api/stock-lots/'.$this->lot('ROTO')->id.'/write-off', ['quantity' => 1, 'reason' => 'Empaque roto'])->assertOk();

        $this->assertEqualsWithDelta(2.0, (float) $this->lot('ROTO')->quantity, 0.0001);
        $this->assertEqualsWithDelta(4.0, (float) $this->lot('PRIMERO')->quantity, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) StockMovement::query()->where('type', 'adjustment_out')->sole()->unit_cost, 0.0001);
    }

    public function test_turning_off_expiration_control_stops_lot_imputation(): void
    {
        $this->receive(5, 2, now()->addDays(10)->toDateString(), 'L-1');
        $this->product->update(['tracks_expiration' => false]);

        $this->sell(2);

        $this->assertEqualsWithDelta(5.0, (float) $this->lot('L-1')->quantity, 0.0001);
    }

    public function test_write_off_requires_adjust_permission_and_cannot_exceed_the_lot(): void
    {
        $this->receive(2, 3, now()->subDay()->toDateString(), 'VENCIDO');
        $this->receive(5, 2, now()->addDays(20)->toDateString(), 'VIGENTE');
        $lot = $this->lot('VENCIDO');

        $this->postJson("/api/stock-lots/{$lot->id}/write-off", ['quantity' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $viewer = User::factory()->create();
        $viewer->tenants()->attach($this->tenant, ['status' => 'active']);
        $role = Role::findOrCreate('Consulta inventario', 'web');
        $role->syncPermissions(['inventory.view']);
        $viewer->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($viewer)->getJson('/api/stock-lots?status=expired')->assertOk();
        $this->actingAs($viewer)->postJson("/api/stock-lots/{$lot->id}/write-off")->assertForbidden();
        $this->assertEqualsWithDelta(2.0, (float) $lot->refresh()->quantity, 0.0001);
    }

    public function test_lots_of_another_company_are_not_reachable(): void
    {
        $this->receive(2, 3, now()->subDay()->toDateString(), 'VENCIDO');
        $lotId = $this->lot('VENCIDO')->id;

        $other = Tenant::create(['name' => 'Otra', 'slug' => 'otra-'.uniqid()]);
        app(TenantManager::class)->set($other);
        setPermissionsTeamId($other->id);
        $intruder = User::factory()->create();
        $intruder->tenants()->attach($other, ['status' => 'active']);
        $role = Role::findOrCreate('Administrador', 'web');
        $role->syncPermissions(BasePermissions::PERMISSIONS);
        $intruder->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($intruder)->withHeader('X-Tenant', $other->slug)->getJson('/api/stock-lots?status=expired')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($intruder)->withHeader('X-Tenant', $other->slug)->postJson("/api/stock-lots/{$lotId}/write-off")->assertNotFound();
    }

    public function test_lot_changes_are_synced_with_their_absolute_quantity(): void
    {
        $this->receive(5, 2, now()->addDays(10)->toDateString(), 'L-9');
        $this->sell(2);

        $events = DB::table('sync_outbox')->where('event_type', 'stock_lot.updated')->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $events->count());
        $last = json_decode($events->last()->payload, true);
        $this->assertSame('L-9', $last['lot_code']);
        $this->assertSame('3.0000', $last['quantity']);
        $this->assertSame('LECHE-1', $last['sku']);
        $this->assertSame('ALM', $last['warehouse_code']);

        DB::table('stock_lot_movements')->delete();
        DB::table('stock_lots')->delete();
        foreach ([json_decode($events->first()->payload, true), $last, $last] as $payload) {
            $payload['source_node_code'] = 'POS-LOCAL-1';
            DB::table('sync_inbox')->insert([
                'tenant_id' => $this->tenant->id,
                'event_uuid' => (string) Str::uuid(),
                'event_type' => 'stock_lot.updated',
                'aggregate_type' => 'stock_lot',
                'aggregate_id' => 1,
                'payload_hash' => hash('sha256', json_encode($payload)),
                'payload' => json_encode($payload),
                'status' => 'received',
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        app(SyncEventApplier::class)->applyPending($this->tenant, 10);

        $replicated = StockLot::query()->sole();
        $this->assertEqualsWithDelta(3.0, (float) $replicated->quantity, 0.0001);
        $this->assertSame(now()->addDays(10)->toDateString(), $replicated->expires_on->toDateString());
        $this->assertNotNull($replicated->updated_at);
    }

    public function test_the_expiration_flag_is_editable_from_the_product_api(): void
    {
        $this->patchJson("/api/products/{$this->product->id}", ['tracks_expiration' => false])
            ->assertOk()
            ->assertJsonPath('data.tracks_expiration', false);

        $this->assertFalse((bool) $this->product->refresh()->tracks_expiration);
    }

    private function lot(string $code): StockLot
    {
        return StockLot::query()->where('lot_code', $code)->where('warehouse_id', $this->warehouse->id)->sole();
    }

    private function available(): float
    {
        return (float) StockBalance::query()->where('warehouse_id', $this->warehouse->id)->where('product_id', $this->product->id)->value('quantity_available');
    }

    private function draft(float $quantity, float $unitCost): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->createDraft($this->user, [
            'supplier_id' => $this->supplier->id,
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'items' => [[
                'warehouse_id' => $this->warehouse->id,
                'product_id' => $this->product->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ]],
        ])->load('items');
    }

    private function receive(float $quantity, float $unitCost, ?string $expiresOn = null, ?string $lotCode = null): PurchaseOrder
    {
        $purchase = $this->draft($quantity, $unitCost);
        $item = ['purchase_item_id' => $purchase->items->first()->id, 'quantity' => $quantity];
        if ($expiresOn !== null) {
            $item['expires_on'] = $expiresOn;
            $item['lot_code'] = $lotCode;
        }

        return app(PurchaseOrderService::class)->receive($purchase, $this->user, ['items' => [$item]]);
    }

    private function sell(float $quantity): Sale
    {
        $sale = app(SaleService::class)->createDraft($this->user, [[
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
        ]]);

        return app(SaleService::class)->confirm($sale, $this->user)->load('items');
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->tenant = Tenant::create(['name' => 'Minimarket', 'slug' => 'minimarket-'.uniqid()]);
        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);
        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant, ['status' => 'active']);
        $role = Role::findOrCreate('Administrador', 'web');
        $role->syncPermissions(BasePermissions::PERMISSIONS);
        $this->user->assignRole($role);
        $this->actingAs($this->user)->withHeader('X-Tenant', $this->tenant->slug);

        $rateType = ExchangeRateType::create(['name' => 'BCV', 'code' => 'BCV', 'is_default' => true]);
        ExchangeRate::create(['exchange_rate_type_id' => $rateType->id, 'rate' => 100, 'effective_at' => now()->subMinute(), 'is_active' => true]);
        $branch = Branch::create(['name' => 'Principal', 'code' => 'PRI']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'ALM']);
        $this->secondWarehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Deposito', 'code' => 'DEP']);
        $this->product = Product::create(['name' => 'Leche', 'sku' => 'LECHE-1', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 4, 'sale_currency' => Product::CURRENCY_USD, 'tracks_expiration' => true]);
        $this->supplier = Supplier::create(['name' => 'Lacteos', 'document_type' => Supplier::DOCUMENT_J, 'document_number' => '600000006']);
    }
}
