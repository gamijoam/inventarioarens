<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerCreditTransaction;
use App\Modules\Customers\Services\CustomerCreditService;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\SalesReturns\Services\SalesReturnService;
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
 * Saldo a favor del cliente: uso serializado por cliente y replicado por sync
 * (hallazgo A5); la comision se revierte al procesar la devolucion, no al
 * solicitarla (hallazgo A3).
 */
class CustomerCreditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    public function test_applying_credit_locks_the_customer_before_reading_the_balance(): void
    {
        $this->issue(50);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->credits()->apply($this->customer, $this->user, ['currency' => 'USD', 'amount' => 20, 'amount_base' => 20, 'amount_local' => 2000]);

        $customerRead = $this->firstIndex($queries, fn (string $sql): bool => str_contains($sql, 'from "customers"'));
        $balanceRead = $this->firstIndex($queries, fn (string $sql): bool => str_contains($sql, 'sum(') && str_contains($sql, 'customer_credit_transactions'));
        $this->assertNotNull($customerRead, 'El uso del saldo debe bloquear la fila del cliente.');
        $this->assertNotNull($balanceRead);
        $this->assertLessThan($balanceRead, $customerRead, 'El cliente se bloquea antes de calcular el saldo disponible.');
        $this->assertEqualsWithDelta(30.0, $this->credits()->availableBase($this->customer), 0.0001);
    }

    public function test_credit_cannot_be_used_beyond_the_available_balance(): void
    {
        $this->issue(50);
        $this->credits()->apply($this->customer, $this->user, ['currency' => 'USD', 'amount' => 30, 'amount_base' => 30, 'amount_local' => 3000]);

        try {
            $this->credits()->apply($this->customer, $this->user, ['currency' => 'USD', 'amount' => 20.01, 'amount_base' => 20.01, 'amount_local' => 2001]);
            $this->fail('Se esperaba el rechazo por saldo insuficiente.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payments', $exception->errors());
        }

        $this->assertEqualsWithDelta(20.0, $this->credits()->availableBase($this->customer), 0.0001);
    }

    public function test_credit_movements_are_queued_for_sync_with_the_customer_identity(): void
    {
        $issued = $this->issue(50);
        $applied = $this->credits()->apply($this->customer, $this->user, ['currency' => 'USD', 'amount' => 20, 'amount_base' => 20, 'amount_local' => 2000]);

        $events = DB::table('sync_outbox')->where('event_type', 'customer_credit.recorded')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $first = json_decode($events[0]->payload, true);
        $second = json_decode($events[1]->payload, true);
        $this->assertSame($issued->id, $first['transaction_id']);
        $this->assertSame(CustomerCreditTransaction::TYPE_ISSUED, $first['type']);
        $this->assertSame('50.0000', $first['amount_base']);
        $this->assertSame('V', $first['customer_document_type']);
        $this->assertSame('12345678', $first['customer_document_number']);
        $this->assertSame($applied->id, $second['transaction_id']);
        $this->assertSame('-20.0000', $second['amount_base']);
    }

    public function test_replicated_credit_is_applied_once_and_matches_the_origin_balance(): void
    {
        $this->issue(50);
        $this->credits()->apply($this->customer, $this->user, ['currency' => 'USD', 'amount' => 20, 'amount_base' => 20, 'amount_local' => 2000]);
        $payloads = DB::table('sync_outbox')->where('event_type', 'customer_credit.recorded')->orderBy('id')->pluck('payload')
            ->map(fn (string $payload): array => json_decode($payload, true) + ['source_node_code' => 'POS-LOCAL-1'])
            ->all();
        DB::table('customer_credit_transactions')->delete();

        foreach ([...$payloads, ...$payloads] as $payload) {
            $this->inbox('customer_credit.recorded', $payload['transaction_id'], $payload);
        }
        app(SyncEventApplier::class)->applyPending($this->tenant, 20);

        $rows = CustomerCreditTransaction::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['applied'], DB::table('sync_inbox')->where('event_type', 'customer_credit.recorded')->distinct()->pluck('status')->all(), 'El duplicado se reconoce como ya aplicado, no como fallo.');
        $this->assertSame(CustomerCreditTransaction::TYPE_ISSUED, $rows[0]->type);
        $this->assertSame(CustomerCreditTransaction::TYPE_APPLIED, $rows[1]->type);
        $this->assertSame('POS-LOCAL-1', $rows[0]->sync_source_node_code);
        $this->assertSame($this->customer->id, $rows[0]->customer_id);
        $this->assertNotNull($rows[0]->created_at);
        $this->assertNotNull($rows[0]->updated_at);
        $this->assertEqualsWithDelta(30.0, $this->credits()->availableBase($this->customer), 0.0001);
    }

    public function test_replicated_credit_waits_until_the_customer_is_synced(): void
    {
        $payload = [
            'transaction_id' => 91,
            'source_node_code' => 'POS-LOCAL-1',
            'customer_document_type' => 'V',
            'customer_document_number' => '99999999',
            'type' => CustomerCreditTransaction::TYPE_ISSUED,
            'currency' => 'USD',
            'amount' => '10.0000',
            'amount_base' => '10.0000',
            'amount_local' => '1000.0000',
        ];
        $this->inbox('customer_credit.recorded', 91, $payload);

        app(SyncEventApplier::class)->applyPending($this->tenant, 10);

        $this->assertSame(0, CustomerCreditTransaction::query()->count());
        $this->assertSame('failed', DB::table('sync_inbox')->where('event_type', 'customer_credit.recorded')->value('status'));

        $late = Customer::create(['name' => 'Cliente tardio', 'document_type' => Customer::DOCUMENT_V, 'document_number' => '99999999']);
        app(SyncEventApplier::class)->applyPending($this->tenant, 10);

        $row = CustomerCreditTransaction::query()->sole();
        $this->assertSame($late->id, $row->customer_id);
        $this->assertEqualsWithDelta(10.0, $this->credits()->availableBase($late), 0.0001);
    }

    public function test_commission_is_not_reversed_when_the_return_is_only_requested_or_rejected(): void
    {
        $sale = $this->sale(2);
        $this->earning($sale);

        $return = $this->requestReturn($sale, 1);
        $this->assertSame(0, CommissionEntry::query()->where('entry_type', CommissionEntry::TYPE_REVERSAL)->count(), 'Solicitar no revierte la comision.');

        app(SalesReturnService::class)->reject($return, $this->user, 'No procede');

        $this->assertSame(0, CommissionEntry::query()->where('entry_type', CommissionEntry::TYPE_REVERSAL)->count(), 'Una devolucion rechazada no toca la comision.');
    }

    public function test_commission_is_reversed_when_the_return_is_processed(): void
    {
        $sale = $this->sale(2);
        $earning = $this->earning($sale);
        $return = $this->requestReturn($sale, 1);
        app(SalesReturnService::class)->approve($return, $this->user);

        $processed = app(SalesReturnService::class)->process($return, $this->user, ['refund_mode' => 'none']);

        $reversal = CommissionEntry::query()->where('entry_type', CommissionEntry::TYPE_REVERSAL)->sole();
        $this->assertSame($earning->id, $reversal->original_entry_id);
        $this->assertSame($return->id, $reversal->sales_return_id);
        $this->assertEqualsWithDelta(-2.0, (float) $reversal->commission_base_amount, 0.0001);
        $this->assertSame($processed->processed_at?->toDateTimeString(), $reversal->earned_at?->toDateTimeString());
    }

    private function credits(): CustomerCreditService
    {
        return app(CustomerCreditService::class);
    }

    private function issue(float $amount): CustomerCreditTransaction
    {
        return $this->credits()->issue($this->customer, $this->user, [
            'currency' => 'USD',
            'amount' => $amount,
            'amount_base' => $amount,
            'amount_local' => $amount * 100,
        ]);
    }

    private function firstIndex(array $queries, callable $match): ?int
    {
        foreach ($queries as $index => $sql) {
            if ($match($sql)) {
                return $index;
            }
        }

        return null;
    }

    private function inbox(string $type, int $aggregateId, array $payload): void
    {
        DB::table('sync_inbox')->insert([
            'tenant_id' => $this->tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => $type,
            'aggregate_type' => 'customer_credit',
            'aggregate_id' => $aggregateId,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sale(float $quantity): Sale
    {
        $sale = app(SaleService::class)->createDraft($this->user, [[
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
        ]], $this->customer->id);

        return app(SaleService::class)->confirm($sale, $this->user)->load('items');
    }

    private function earning(Sale $sale): CommissionEntry
    {
        return CommissionEntry::create([
            'entry_uuid' => (string) Str::uuid(),
            'sale_id' => $sale->id,
            'sale_item_id' => $sale->items->first()->id,
            'beneficiary_user_id' => $this->user->id,
            'beneficiary_role' => 'seller',
            'entry_type' => CommissionEntry::TYPE_EARNING,
            'plan_name_snapshot' => 'Plan 2%',
            'percentage_snapshot' => 2,
            'sale_currency' => 'USD',
            'source_amount' => 200,
            'eligible_base_amount' => 200,
            'commission_base_amount' => 4,
            'status' => CommissionEntry::STATUS_AVAILABLE,
            'earned_at' => now()->subDay(),
            'available_at' => now()->subDay(),
        ]);
    }

    private function requestReturn(Sale $sale, float $quantity): SalesReturn
    {
        return app(SalesReturnService::class)->create($this->user, [
            'sale_id' => $sale->id,
            'reason' => 'Cliente devolvio',
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => $quantity]],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->tenant = Tenant::create(['name' => 'Saldo', 'slug' => 'saldo-'.uniqid()]);
        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);
        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant, ['status' => 'active']);
        $role = Role::findOrCreate('Administrador', 'web');
        $role->syncPermissions(BasePermissions::PERMISSIONS);
        $this->user->assignRole($role);

        $rateType = ExchangeRateType::create(['name' => 'BCV', 'code' => 'BCV', 'is_default' => true]);
        ExchangeRate::create(['exchange_rate_type_id' => $rateType->id, 'rate' => 100, 'effective_at' => now()->subMinute(), 'is_active' => true]);
        $branch = Branch::create(['name' => 'Principal', 'code' => 'PRI']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'ALM']);
        $this->product = Product::create(['name' => 'Producto', 'sku' => 'CC-1', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 100, 'sale_currency' => Product::CURRENCY_USD]);
        StockBalance::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id, 'quantity_available' => 10]);
        $this->customer = Customer::create(['name' => 'Cliente', 'document_type' => Customer::DOCUMENT_V, 'document_number' => '12345678']);
    }
}
