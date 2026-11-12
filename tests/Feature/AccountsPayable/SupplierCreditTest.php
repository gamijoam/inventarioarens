<?php

namespace Tests\Feature\AccountsPayable;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Models\AccountsPayablePayment;
use App\Modules\AccountsPayable\Services\AccountsPayableService;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Products\Models\Product;
use App\Modules\PurchaseReturns\Services\PurchaseReturnService;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Services\PurchaseOrderService;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Models\SupplierCreditTransaction;
use App\Modules\Suppliers\Services\SupplierCreditService;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Una devolucion a proveedor posterior al pago deja un credito con el
 * proveedor (auditoria 2026-11-11, hallazgo A6): se puede aplicar a otra CxP
 * del mismo proveedor o cobrar como reembolso.
 */
class SupplierCreditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    private ExchangeRateType $rateType;

    public function test_return_after_full_payment_creates_supplier_credit(): void
    {
        [$purchase, $account] = $this->purchase(5);
        $this->pay($account, 50);

        $this->returnUnits($purchase, 2);

        $account->refresh();
        $this->assertSame(AccountsPayable::STATUS_PAID, $account->status);
        $this->assertEqualsWithDelta(0.0, (float) $account->balance_base_amount, 0.0001);

        $credit = SupplierCreditTransaction::query()->sole();
        $this->assertSame(SupplierCreditTransaction::TYPE_RETURN_CREDIT, $credit->type);
        $this->assertSame($this->supplier->id, $credit->supplier_id);
        $this->assertSame($account->id, $credit->accounts_payable_id);
        $this->assertNotNull($credit->purchase_return_id);
        $this->assertEqualsWithDelta(20.0, (float) $credit->amount_base, 0.0001);
        $this->assertEqualsWithDelta(2000.0, (float) $credit->amount_local, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $credit->exchange_rate, 0.0001);

        $this->getJson("/api/suppliers/{$this->supplier->id}/credit")
            ->assertOk()
            ->assertJsonPath('data.available_base_amount', 20)
            ->assertJsonPath('data.available_local_amount', 2000)
            ->assertJsonPath('data.transactions.0.type', SupplierCreditTransaction::TYPE_RETURN_CREDIT);
    }

    public function test_return_after_paying_a_bolivar_invoice_credits_the_bolivar_excess(): void
    {
        [$purchase, $account] = $this->purchase(5, PurchaseOrder::CURRENCY_VES, 1000);
        $this->assertEqualsWithDelta(5000.0, (float) $account->original_local_amount, 0.0001);
        app(AccountsPayableService::class)->registerPayment($account, $this->user, ['payment_currency' => 'VES', 'amount' => 5000, 'method' => 'transfer']);

        $this->returnUnits($purchase, 2);

        $credit = SupplierCreditTransaction::query()->sole();
        $this->assertSame('VES', $credit->currency);
        $this->assertEqualsWithDelta(2000.0, (float) $credit->amount, 0.0001);
        $this->assertEqualsWithDelta(2000.0, (float) $credit->amount_local, 0.0001);
        $this->assertEqualsWithDelta(20.0, (float) $credit->amount_base, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $account->refresh()->balance_local_amount, 0.0001);
    }

    public function test_return_after_partial_payment_credits_only_the_excess(): void
    {
        [$purchase, $account] = $this->purchase(5);
        $this->pay($account, 40);

        $this->returnUnits($purchase, 2);

        $this->assertEqualsWithDelta(0.0, (float) $account->refresh()->balance_base_amount, 0.0001);
        $this->assertEqualsWithDelta(10.0, $this->available(), 0.0001, 'Debia $50, pago $40, devolvio $20: el proveedor debe $10.');
    }

    public function test_return_before_payment_reduces_the_debt_without_credit(): void
    {
        [$purchase, $account] = $this->purchase(5);

        $this->returnUnits($purchase, 2);

        $this->assertEqualsWithDelta(30.0, (float) $account->refresh()->balance_base_amount, 0.0001);
        $this->assertSame(0, SupplierCreditTransaction::query()->count());
    }

    public function test_successive_returns_do_not_double_count_the_credit(): void
    {
        [$purchase, $account] = $this->purchase(5);
        $this->pay($account, 50);

        $this->returnUnits($purchase, 2);
        $this->returnUnits($purchase, 1);

        $this->assertSame(2, SupplierCreditTransaction::query()->count());
        $this->assertEqualsWithDelta(30.0, $this->available(), 0.0001);
    }

    public function test_credit_can_be_applied_to_another_payable_of_the_same_supplier(): void
    {
        [$first, $firstAccount] = $this->purchase(5);
        $this->pay($firstAccount, 50);
        $this->returnUnits($first, 2);
        [, $second] = $this->purchase(3);

        $this->postJson("/api/accounts-payable/{$second->id}/supplier-credit", ['amount' => 15])
            ->assertCreated()
            ->assertJsonPath('data.method', AccountsPayablePayment::METHOD_SUPPLIER_CREDIT);

        $second->refresh();
        $this->assertEqualsWithDelta(15.0, (float) $second->paid_base_amount, 0.0001);
        $this->assertEqualsWithDelta(1500.0, (float) $second->paid_local_amount, 0.0001);
        $this->assertEqualsWithDelta(15.0, (float) $second->balance_base_amount, 0.0001);
        $this->assertSame(AccountsPayable::STATUS_PARTIAL, $second->status);

        $payment = AccountsPayablePayment::query()->where('accounts_payable_id', $second->id)->sole();
        $this->assertEqualsWithDelta(15.0, (float) $payment->amount_base, 0.0001);
        $this->assertEqualsWithDelta(1500.0, (float) $payment->amount_local, 0.0001);

        $applied = SupplierCreditTransaction::query()->where('type', SupplierCreditTransaction::TYPE_APPLIED)->sole();
        $this->assertEqualsWithDelta(-15.0, (float) $applied->amount_base, 0.0001);
        $this->assertSame($second->id, $applied->accounts_payable_id);
        $this->assertSame($payment->id, $applied->accounts_payable_payment_id);
        $this->assertEqualsWithDelta(5.0, $this->available(), 0.0001);
        $this->assertSame(0, CashRegisterMovement::query()->count(), 'Aplicar credito no mueve dinero en caja.');
    }

    public function test_credit_application_cannot_exceed_the_available_credit(): void
    {
        [$first, $firstAccount] = $this->purchase(5);
        $this->pay($firstAccount, 50);
        $this->returnUnits($first, 1);
        [, $second] = $this->purchase(3);

        $this->postJson("/api/accounts-payable/{$second->id}/supplier-credit", ['amount' => 10.01])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertEqualsWithDelta(30.0, (float) $second->refresh()->balance_base_amount, 0.0001);
        $this->assertEqualsWithDelta(10.0, $this->available(), 0.0001);
    }

    public function test_credit_application_cannot_exceed_the_payable_balance(): void
    {
        [$first, $firstAccount] = $this->purchase(5);
        $this->pay($firstAccount, 50);
        $this->returnUnits($first, 5);
        [, $second] = $this->purchase(1);

        $this->postJson("/api/accounts-payable/{$second->id}/supplier-credit", ['amount' => 10.01])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertEqualsWithDelta(50.0, $this->available(), 0.0001);
    }

    public function test_credit_of_one_supplier_cannot_pay_another_supplier(): void
    {
        [$first, $firstAccount] = $this->purchase(5);
        $this->pay($firstAccount, 50);
        $this->returnUnits($first, 2);
        $this->supplier = Supplier::create(['name' => 'Otro proveedor', 'document_type' => Supplier::DOCUMENT_J, 'document_number' => '200000002']);
        [, $other] = $this->purchase(3);

        $this->postJson("/api/accounts-payable/{$other->id}/supplier-credit", ['amount' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, AccountsPayablePayment::query()->where('accounts_payable_id', $other->id)->count());
    }

    public function test_refund_received_from_the_supplier_consumes_the_credit(): void
    {
        [$purchase, $account] = $this->purchase(5);
        $this->pay($account, 50);
        $this->returnUnits($purchase, 2);

        $this->postJson("/api/suppliers/{$this->supplier->id}/credit-refunds", [
            'payment_currency' => 'VES',
            'amount' => 1500,
            'method' => 'transfer',
            'reference' => 'TRF-1',
        ])->assertCreated()
            ->assertJsonPath('data.type', SupplierCreditTransaction::TYPE_REFUND_RECEIVED);

        $refund = SupplierCreditTransaction::query()->where('type', SupplierCreditTransaction::TYPE_REFUND_RECEIVED)->sole();
        $this->assertEqualsWithDelta(-15.0, (float) $refund->amount_base, 0.0001);
        $this->assertEqualsWithDelta(-1500.0, (float) $refund->amount_local, 0.0001);
        $this->assertSame('BCV', $refund->exchange_rate_type_code);
        $this->assertEqualsWithDelta(100.0, (float) $refund->exchange_rate, 0.0001);
        $this->assertSame('TRF-1', $refund->reference);
        $this->assertEqualsWithDelta(5.0, $this->available(), 0.0001);
    }

    public function test_cash_refund_enters_the_open_cash_register(): void
    {
        [$purchase, $account] = $this->purchase(5);
        $this->pay($account, 50);
        $this->returnUnits($purchase, 2);
        $branch = Branch::query()->firstOrFail();
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja 1', 'code' => 'CAJA-1']);
        $session = app(CashRegisterService::class)->open($this->user, $branch, $register, $this->user, ['opening_cash_usd' => 0, 'opening_cash_ves' => 0]);

        $this->postJson("/api/suppliers/{$this->supplier->id}/credit-refunds", [
            'payment_currency' => 'USD',
            'amount' => 20,
            'method' => 'cash',
            'cash_register_session_id' => $session->id,
        ])->assertCreated();

        $movement = CashRegisterMovement::query()->where('source_type', SupplierCreditTransaction::class)->sole();
        $this->assertSame(CashRegisterMovement::TYPE_INFLOW, $movement->type);
        $this->assertEqualsWithDelta(20.0, (float) $movement->amount_base, 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->available(), 0.0001);
    }

    public function test_cash_refund_requires_an_open_cash_register(): void
    {
        [$purchase, $account] = $this->purchase(5);
        $this->pay($account, 50);
        $this->returnUnits($purchase, 2);

        $this->postJson("/api/suppliers/{$this->supplier->id}/credit-refunds", [
            'payment_currency' => 'USD',
            'amount' => 10,
            'method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('cash_register_session_id');

        $this->assertEqualsWithDelta(20.0, $this->available(), 0.0001);
    }

    public function test_refund_cannot_exceed_the_available_credit(): void
    {
        [$purchase, $account] = $this->purchase(5);
        $this->pay($account, 50);
        $this->returnUnits($purchase, 2);

        $this->postJson("/api/suppliers/{$this->supplier->id}/credit-refunds", [
            'payment_currency' => 'USD',
            'amount' => 20.02,
            'method' => 'transfer',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertSame(1, SupplierCreditTransaction::query()->count());
    }

    public function test_apply_and_refund_require_the_pay_permission(): void
    {
        [$first, $firstAccount] = $this->purchase(5);
        $this->pay($firstAccount, 50);
        $this->returnUnits($first, 2);
        [, $second] = $this->purchase(3);

        $viewer = User::factory()->create();
        $viewer->tenants()->attach($this->tenant, ['status' => 'active']);
        $role = Role::findOrCreate('Consulta', 'web');
        $role->syncPermissions(['accounts_payable.view', 'suppliers.view']);
        $viewer->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($viewer)->getJson("/api/suppliers/{$this->supplier->id}/credit")->assertOk();
        $this->actingAs($viewer)->postJson("/api/accounts-payable/{$second->id}/supplier-credit", ['amount' => 5])->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/suppliers/{$this->supplier->id}/credit-refunds", [
            'payment_currency' => 'USD', 'amount' => 5, 'method' => 'transfer',
        ])->assertForbidden();

        $this->assertEqualsWithDelta(20.0, $this->available(), 0.0001);
    }

    public function test_supplier_credit_of_another_tenant_is_not_reachable(): void
    {
        [$purchase, $account] = $this->purchase(5);
        $this->pay($account, 50);
        $this->returnUnits($purchase, 2);
        $supplierId = $this->supplier->id;

        $other = Tenant::create(['name' => 'Otra', 'slug' => 'otra-'.uniqid()]);
        app(TenantManager::class)->set($other);
        setPermissionsTeamId($other->id);
        $intruder = User::factory()->create();
        $intruder->tenants()->attach($other, ['status' => 'active']);
        $role = Role::findOrCreate('Administrador', 'web');
        $role->syncPermissions(BasePermissions::PERMISSIONS);
        $intruder->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($intruder)->withHeader('X-Tenant', $other->slug)
            ->getJson("/api/suppliers/{$supplierId}/credit")->assertNotFound();
        $this->actingAs($intruder)->withHeader('X-Tenant', $other->slug)
            ->postJson("/api/suppliers/{$supplierId}/credit-refunds", ['payment_currency' => 'USD', 'amount' => 5, 'method' => 'transfer'])
            ->assertNotFound();

        $this->assertSame(1, SupplierCreditTransaction::withoutGlobalScopes()->count());
    }

    public function test_credit_movements_are_queued_for_sync_and_applied_once_on_another_node(): void
    {
        [$purchase, $account] = $this->purchase(5);
        $this->pay($account, 50);
        $this->returnUnits($purchase, 2);

        $event = DB::table('sync_outbox')->where('event_type', 'supplier_credit.recorded')->sole();
        $payload = json_decode($event->payload, true);
        $this->assertSame(SupplierCreditTransaction::TYPE_RETURN_CREDIT, $payload['type']);
        $this->assertSame('20.0000', $payload['amount_base']);
        $this->assertSame($this->supplier->document_number, $payload['supplier_document_number']);
        $this->assertSame($account->document_number, $payload['payable_document']);

        DB::table('supplier_credit_transactions')->delete();
        $payload['source_node_code'] = 'POS-LOCAL-1';
        foreach ([1, 2] as $ignored) {
            DB::table('sync_inbox')->insert([
                'tenant_id' => $this->tenant->id,
                'event_uuid' => (string) Str::uuid(),
                'event_type' => 'supplier_credit.recorded',
                'aggregate_type' => 'supplier_credit',
                'aggregate_id' => $payload['transaction_id'],
                'payload_hash' => hash('sha256', json_encode($payload)),
                'payload' => json_encode($payload),
                'status' => 'received',
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        app(SyncEventApplier::class)->applyPending($this->tenant, 10);

        $replicated = SupplierCreditTransaction::query()->sole();
        $this->assertSame('POS-LOCAL-1', $replicated->sync_source_node_code);
        $this->assertSame($this->supplier->id, $replicated->supplier_id);
        $this->assertSame($account->id, $replicated->accounts_payable_id);
        $this->assertEqualsWithDelta(20.0, (float) $replicated->amount_base, 0.0001);
        $this->assertNotNull($replicated->created_at);
        $this->assertNotNull($replicated->updated_at);
        $this->assertEqualsWithDelta(20.0, $this->available(), 0.0001);
    }

    private function available(): float
    {
        return app(SupplierCreditService::class)->availableBase($this->supplier);
    }

    /**
     * @return array{0: PurchaseOrder, 1: AccountsPayable}
     */
    private function purchase(float $quantity, string $currency = PurchaseOrder::CURRENCY_USD, float $unitCost = 10): array
    {
        $service = app(PurchaseOrderService::class);
        $purchase = $service->createDraft($this->user, [
            'supplier_id' => $this->supplier->id,
            'purchase_currency' => $currency,
            'items' => [[
                'warehouse_id' => $this->warehouse->id,
                'product_id' => $this->product->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ]],
        ]);
        $purchase = $service->receive($purchase, $this->user);

        return [$purchase->load('items'), AccountsPayable::query()->where('purchase_order_id', $purchase->id)->sole()];
    }

    private function pay(AccountsPayable $account, float $amount): void
    {
        app(AccountsPayableService::class)->registerPayment($account, $this->user, [
            'payment_currency' => 'USD',
            'amount' => $amount,
            'method' => 'transfer',
        ]);
    }

    private function returnUnits(PurchaseOrder $purchase, float $quantity): void
    {
        app(PurchaseReturnService::class)->create($this->user, [
            'purchase_order_id' => $purchase->id,
            'reason' => 'Defecto',
            'items' => [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => $quantity]],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->tenant = Tenant::create(['name' => 'Credito', 'slug' => 'credito-'.uniqid()]);
        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);
        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant, ['status' => 'active']);
        $role = Role::findOrCreate('Administrador', 'web');
        $role->syncPermissions(BasePermissions::PERMISSIONS);
        $this->user->assignRole($role);
        $this->actingAs($this->user)->withHeader('X-Tenant', $this->tenant->slug);

        $this->rateType = ExchangeRateType::create(['name' => 'BCV', 'code' => 'BCV', 'is_default' => true]);
        ExchangeRate::create(['exchange_rate_type_id' => $this->rateType->id, 'rate' => 100, 'effective_at' => now()->subMinute(), 'is_active' => true]);

        $branch = Branch::create(['name' => 'Principal', 'code' => 'PRI']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Almacen', 'code' => 'ALM']);
        $this->product = Product::create(['name' => 'Producto', 'sku' => 'SC-1', 'tracking_type' => Product::TRACKING_QUANTITY, 'base_price' => 20, 'sale_currency' => Product::CURRENCY_USD]);
        $this->supplier = Supplier::create(['name' => 'Proveedor', 'document_type' => Supplier::DOCUMENT_J, 'document_number' => '100000001']);
    }
}
