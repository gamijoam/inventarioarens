<?php

namespace Tests\Feature\SalesReturns;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Customers\Models\Customer;
use App\Modules\FinancialAdjustments\Models\FinancialAdjustment;
use App\Modules\FinancialAdjustments\Services\FinancialAdjustmentService;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\SalesReturns\Models\SalesReturn;
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
 * Contrato contable entre devoluciones, reversos y cuentas por cobrar
 * (auditoria 2026-11-11, criticos C1, C2 y C3).
 *
 * Regla: lo devuelto reduce primero la deuda pendiente de la venta; solo el
 * excedente ya cobrado es reembolsable (efectivo o saldo a favor). Un reverso
 * no puede convivir con devoluciones y anula la cuenta por cobrar.
 */
class SalesReturnAccountingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    // ---------- C1: saldo a favor y reembolsos topados por lo cobrado ----------

    public function test_unpaid_credit_sale_cannot_be_refunded_as_customer_credit(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->creditSale($ctx, $sid, 1, []);

        $returnId = $this->approvedReturn($ctx, $order['sale_id'], 1);
        $this->processReturn($ctx, $returnId, ['refund_mode' => 'customer_credit'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['refund_mode']);

        $this->assertSame(0, DB::table('customer_credit_transactions')->count(), 'No debe emitirse saldo a favor sobre una venta no cobrada.');
        $this->assertEqualsWithDelta(100.0, $this->receivable($order['sale_id'])->balance_base_amount, 0.0001);
        $this->assertSame(SalesReturn::STATUS_APPROVED, SalesReturn::query()->find($returnId)->status, 'El rechazo no deja la devolucion a medias.');
    }

    public function test_unpaid_credit_sale_return_applied_to_debt_only(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->creditSale($ctx, $sid, 1, []);
        $stockBefore = $this->available($ctx);

        $returnId = $this->approvedReturn($ctx, $order['sale_id'], 1);
        $this->processReturn($ctx, $returnId, ['refund_mode' => 'receivable'])->assertOk();

        $account = $this->receivable($order['sale_id']);
        $this->assertEqualsWithDelta(0.0, (float) $account->balance_base_amount, 0.0001);
        $this->assertSame(AccountsReceivable::STATUS_PAID, $account->status);
        $this->assertSame(0, DB::table('customer_credit_transactions')->count());
        $this->assertEqualsWithDelta($stockBefore + 1, $this->available($ctx), 0.0001);
        $this->assertSame('0.0000', $this->sessionData($ctx, $sid)['expected_cash_usd']);
    }

    public function test_partial_return_on_partially_paid_credit_sale_only_reduces_debt(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->creditSale($ctx, $sid, 2, [['method' => 'cash', 'currency' => 'USD', 'amount' => 50]]);

        $returnId = $this->approvedReturn($ctx, $order['sale_id'], 1);
        $this->processReturn($ctx, $returnId, ['refund_mode' => 'customer_credit'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['refund_mode']);

        $this->processReturn($ctx, $returnId, ['refund_mode' => 'none'])->assertOk();
        $this->assertEqualsWithDelta(50.0, (float) $this->receivable($order['sale_id'])->balance_base_amount, 0.0001, '200 - 50 cobrado - 100 devuelto = 50 pendiente.');
        $this->assertSame(0, DB::table('customer_credit_transactions')->count());
    }

    public function test_full_return_on_partially_paid_credit_sale_refunds_only_what_was_collected(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->creditSale($ctx, $sid, 2, [['method' => 'cash', 'currency' => 'USD', 'amount' => 50]]);

        $returnId = $this->approvedReturn($ctx, $order['sale_id'], 2);
        $this->processReturn($ctx, $returnId, $this->cashRefund($sid, 100))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['refund_amount']);

        $this->processReturn($ctx, $returnId, ['refund_mode' => 'customer_credit'])->assertOk();

        $this->assertEqualsWithDelta(50.0, (float) DB::table('customer_credit_transactions')->where('type', 'issued')->sum('amount_base'), 0.0001, 'Solo los 50 cobrados son saldo a favor.');
        $account = $this->receivable($order['sale_id']);
        $this->assertEqualsWithDelta(0.0, (float) $account->balance_base_amount, 0.0001);
        $this->assertSame(AccountsReceivable::STATUS_PAID, $account->status);
        $this->assertEqualsWithDelta(50.0, $this->creditAvailable($ctx, $order['customer_id']), 0.0001);
    }

    public function test_full_return_on_partially_paid_credit_sale_allows_cash_refund_of_collected_amount(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->creditSale($ctx, $sid, 2, [['method' => 'cash', 'currency' => 'USD', 'amount' => 50]]);

        $returnId = $this->approvedReturn($ctx, $order['sale_id'], 2);
        $this->processReturn($ctx, $returnId, $this->cashRefund($sid, 50))->assertOk();

        $this->assertSame('0.0000', $this->sessionData($ctx, $sid)['expected_cash_usd'], 'Entraron 50 y salieron 50.');
        $account = $this->receivable($order['sale_id']);
        $this->assertEqualsWithDelta(0.0, (float) $account->balance_base_amount, 0.0001);
        $this->assertSame(AccountsReceivable::STATUS_PAID, $account->status);
    }

    public function test_paid_cash_sale_return_still_issues_full_customer_credit(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->paidSale($ctx, $sid, 1);

        $returnId = $this->approvedReturn($ctx, $order['sale_id'], 1);
        $this->processReturn($ctx, $returnId, ['refund_mode' => 'customer_credit'])->assertOk();

        $credit = DB::table('customer_credit_transactions')->where('type', 'issued')->first();
        $this->assertEqualsWithDelta(100.0, (float) $credit->amount_base, 0.0001);
        $this->assertEqualsWithDelta(50000.0, (float) $credit->amount_local, 0.0001, 'Doble cuenta con la tasa de la venta.');
        $account = $this->receivable($order['sale_id']);
        $this->assertEqualsWithDelta(0.0, (float) $account->balance_base_amount, 0.0001);
        $this->assertSame(AccountsReceivable::STATUS_PAID, $account->status);
    }

    public function test_second_return_cannot_refund_more_than_collected_minus_previous_refunds(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->creditSale($ctx, $sid, 2, [['method' => 'cash', 'currency' => 'USD', 'amount' => 150]]);

        $first = $this->approvedReturn($ctx, $order['sale_id'], 1);
        $this->processReturn($ctx, $first, ['refund_mode' => 'customer_credit'])->assertOk();
        $this->assertEqualsWithDelta(50.0, (float) DB::table('customer_credit_transactions')->sum('amount_base'), 0.0001, 'Pendiente 50: la devolucion de 100 cubre la deuda y reembolsa 50.');

        $second = $this->approvedReturn($ctx, $order['sale_id'], 1);
        $this->processReturn($ctx, $second, $this->cashRefund($sid, 100.01))->assertStatus(422);
        $this->processReturn($ctx, $second, $this->cashRefund($sid, 100))->assertOk();

        $this->assertEqualsWithDelta(150.0, (float) DB::table('customer_credit_transactions')->sum('amount_base') + (float) SalesReturn::query()->sum('refund_amount_base'), 0.0001, 'Nunca se reembolsa mas de lo cobrado.');
        $this->assertEqualsWithDelta(0.0, (float) $this->receivable($order['sale_id'])->balance_base_amount, 0.0001);
    }

    public function test_debt_written_off_by_adjustment_is_not_refundable(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->creditSale($ctx, $sid, 2, []);
        $account = $this->receivable($order['sale_id']);
        $this->useTenant($ctx['tenant']);
        app(FinancialAdjustmentService::class)->create($ctx['cashier'], [
            'account_type' => FinancialAdjustment::ACCOUNT_RECEIVABLE,
            'account_id' => $account->id,
            'currency' => 'USD',
            'amount' => 100,
            'reason' => 'Descuento comercial',
        ]);
        $this->assertEqualsWithDelta(100.0, (float) $this->receivable($order['sale_id'])->balance_base_amount, 0.0001);

        $returnId = $this->approvedReturn($ctx, $order['sale_id'], 2);
        $this->processReturn($ctx, $returnId, ['refund_mode' => 'customer_credit'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['refund_mode']);
        $this->assertSame(0, DB::table('customer_credit_transactions')->count(), 'Lo condonado no es dinero cobrado: no se reembolsa.');
    }

    public function test_receivable_stores_only_the_return_amount_applied_to_debt(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->creditSale($ctx, $sid, 2, [['method' => 'cash', 'currency' => 'USD', 'amount' => 50]]);

        $returnId = $this->approvedReturn($ctx, $order['sale_id'], 2);
        $this->processReturn($ctx, $returnId, ['refund_mode' => 'customer_credit'])->assertOk();

        $account = $this->receivable($order['sale_id']);
        $this->assertEqualsWithDelta(150.0, (float) $account->returned_base_amount, 0.0001, 'De los 200 devueltos, 50 se reembolsan y 150 cancelan deuda.');
        $this->assertEqualsWithDelta(75000.0, (float) $account->returned_local_amount, 0.0001);
    }

    // ---------- C2: reverso y devoluciones son excluyentes ----------

    public function test_sale_with_processed_return_cannot_be_reversed(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->paidSale($ctx, $sid, 1);
        $stockAfterSale = $this->available($ctx);

        $returnId = $this->approvedReturn($ctx, $order['sale_id'], 1);
        $this->processReturn($ctx, $returnId, $this->cashRefund($sid, 100))->assertOk();

        $this->reverse($ctx, $order['order_id'], $sid)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pos_order_id']);

        $this->assertEqualsWithDelta(100.0, (float) CashRegisterMovement::query()->where('type', CashRegisterMovement::TYPE_OUTFLOW)->sum('amount_base'), 0.0001, 'Solo sale el reembolso de la devolucion.');
        $this->assertSame('0.0000', $this->sessionData($ctx, $sid)['expected_cash_usd']);
        $this->assertEqualsWithDelta($stockAfterSale + 1, $this->available($ctx), 0.0001, 'Sin stock fantasma.');
        $this->assertSame(Sale::STATUS_CONFIRMED, Sale::query()->find($order['sale_id'])->status);
    }

    public function test_sale_with_open_return_request_cannot_be_reversed(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->paidSale($ctx, $sid, 1);
        $this->requestedReturn($ctx, $order['sale_id'], 1);

        $this->reverse($ctx, $order['order_id'], $sid)->assertStatus(422)->assertJsonValidationErrors(['pos_order_id']);
        $this->assertSame(0, DB::table('sale_reversals')->count());
    }

    public function test_rejected_return_does_not_block_reversal(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->paidSale($ctx, $sid, 1);
        $returnId = $this->requestedReturn($ctx, $order['sale_id'], 1);
        $this->actingAs($ctx['cashier'])->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson("/api/sales-returns/{$returnId}/reject", ['reason' => 'No procede'])->assertOk();

        $this->reverse($ctx, $order['order_id'], $sid)->assertCreated();
        $this->assertSame('0.0000', $this->sessionData($ctx, $sid)['expected_cash_usd']);
    }

    public function test_return_cannot_be_processed_or_approved_once_the_sale_is_voided(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->paidSale($ctx, $sid, 2);
        $approved = $this->approvedReturn($ctx, $order['sale_id'], 1);
        $requested = $this->requestedReturn($ctx, $order['sale_id'], 1);
        $stock = $this->available($ctx);

        // Simula una venta anulada por otro nodo (sync) o por un flujo legado.
        DB::table('sales')->where('id', $order['sale_id'])->update(['status' => Sale::STATUS_VOIDED]);

        $this->processReturn($ctx, $approved, $this->cashRefund($sid, 100))->assertStatus(422)->assertJsonValidationErrors(['sale_id']);
        $this->actingAs($ctx['cashier'])->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson("/api/sales-returns/{$requested}/approve")->assertStatus(422)->assertJsonValidationErrors(['sale_id']);

        $this->assertEqualsWithDelta($stock, $this->available($ctx), 0.0001);
        $this->assertSame('200.0000', $this->sessionData($ctx, $sid)['expected_cash_usd']);
    }

    // ---------- C3: el reverso anula la cuenta por cobrar ----------

    public function test_reversing_partially_paid_credit_sale_voids_receivable_and_refunds_all_collections(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->creditSale($ctx, $sid, 2, [['method' => 'cash', 'currency' => 'USD', 'amount' => 50]]);
        $account = $this->receivable($order['sale_id']);
        $this->collect($ctx, $account->id, $sid, ['payment_currency' => 'USD', 'amount' => 30, 'method' => 'cash', 'exchange_rate_type_id' => $ctx['rateType']->id])->assertCreated();
        $this->assertSame('80.0000', $this->sessionData($ctx, $sid)['expected_cash_usd']);
        $stock = $this->available($ctx);

        $reversal = $this->reverse($ctx, $order['order_id'], $sid)->assertCreated();

        $account = $this->receivable($order['sale_id']);
        $this->assertSame(AccountsReceivable::STATUS_VOIDED, $account->status);
        $this->assertEqualsWithDelta(0.0, (float) $account->balance_base_amount, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $account->balance_local_amount, 0.0001);
        $this->assertSame('0.0000', $this->sessionData($ctx, $sid)['expected_cash_usd'], 'Se devuelven el abono POS (50) y el cobro de CxC (30).');
        $this->assertSame(2, CashRegisterMovement::query()->where('source_type', 'sale_reversal')->count(), 'El pago espejo POS-PAYMENT no se reembolsa dos veces.');
        $this->assertEqualsWithDelta(80.0, (float) $reversal->json('data.reversed_base_amount'), 0.0001);
        $this->assertEqualsWithDelta(40000.0, (float) $reversal->json('data.reversed_local_amount'), 0.0001);
        $this->assertEqualsWithDelta($stock + 2, $this->available($ctx), 0.0001);
    }

    public function test_unpaid_credit_sale_can_be_reversed_and_voids_receivable(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->creditSale($ctx, $sid, 1, []);
        $stock = $this->available($ctx);

        $reversal = $this->reverse($ctx, $order['order_id'], $sid)->assertCreated();
        $this->assertEqualsWithDelta(0.0, (float) $reversal->json('data.reversed_base_amount'), 0.0001);

        $account = $this->receivable($order['sale_id']);
        $this->assertSame(AccountsReceivable::STATUS_VOIDED, $account->status);
        $this->assertEqualsWithDelta(0.0, (float) $account->balance_base_amount, 0.0001);
        $this->assertSame(0, CashRegisterMovement::query()->where('source_type', 'sale_reversal')->count());
        $this->assertEqualsWithDelta($stock + 1, $this->available($ctx), 0.0001);
        $this->assertSame(Sale::STATUS_VOIDED, Sale::query()->find($order['sale_id'])->status);
    }

    public function test_reversal_refunds_electronic_receivable_collection_with_reference(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->creditSale($ctx, $sid, 1, []);
        $account = $this->receivable($order['sale_id']);
        $this->collect($ctx, $account->id, $sid, ['payment_currency' => 'USD', 'amount' => 40, 'method' => 'transfer', 'reference' => 'TRF-1'])->assertCreated();

        $this->reverse($ctx, $order['order_id'], $sid, null)->assertStatus(422)->assertJsonValidationErrors(['refund_reference']);
        $this->reverse($ctx, $order['order_id'], $sid, 'REEMB-TRF-1')->assertCreated();

        $this->assertDatabaseHas('cash_register_movements', [
            'cash_register_session_id' => $sid,
            'type' => CashRegisterMovement::TYPE_OUTFLOW,
            'method' => 'transfer',
            'reference' => 'REEMB-TRF-1',
            'source_type' => 'sale_reversal',
        ]);
        $this->assertSame('0.0000', $this->sessionData($ctx, $sid)['expected_cash_usd']);
        $this->assertSame('0.0000', $this->sessionData($ctx, $sid)['expected_base_amount']);
    }

    public function test_voided_receivable_cannot_receive_new_collections(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->creditSale($ctx, $sid, 1, []);
        $this->reverse($ctx, $order['order_id'], $sid)->assertCreated();

        $account = $this->receivable($order['sale_id']);
        $message = $this->collect($ctx, $account->id, $sid, ['payment_currency' => 'USD', 'amount' => 10, 'method' => 'cash'])
            ->assertStatus(422)
            ->json('errors.amount.0');
        $this->assertStringContainsString('anulada', $message);
        $this->assertSame('0.0000', $this->sessionData($ctx, $sid)['expected_cash_usd']);
    }

    public function test_recalculating_a_voided_receivable_keeps_it_voided(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sid = $this->openSession($ctx);
        $order = $this->paidSale($ctx, $sid, 1);
        $returnId = $this->requestedReturn($ctx, $order['sale_id'], 1);
        $this->actingAs($ctx['cashier'])->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson("/api/sales-returns/{$returnId}/reject", ['reason' => 'No procede'])->assertOk();
        $this->reverse($ctx, $order['order_id'], $sid)->assertCreated();

        $this->useTenant($ctx['tenant']);
        app(AccountsReceivableService::class)->applySalesReturn(SalesReturn::query()->findOrFail($returnId));

        $account = $this->receivable($order['sale_id']);
        $this->assertSame(AccountsReceivable::STATUS_VOIDED, $account->status);
        $this->assertEqualsWithDelta(0.0, (float) $account->balance_base_amount, 0.0001);
    }

    // ---------- helpers ----------

    private function creditSale(array $ctx, int $sessionId, float $quantity, array $payments): array
    {
        $this->useTenant($ctx['tenant']);
        $customer = Customer::create(['name' => 'Cliente '.uniqid(), 'document_type' => Customer::DOCUMENT_V, 'document_number' => (string) random_int(100000, 999999)]);
        $response = $this->actingAs($ctx['cashier'])->withHeader('X-Tenant', $ctx['tenant']->slug)->postJson('/api/pos/checkouts', [
            'cash_register_session_id' => $sessionId,
            'customer_id' => $customer->id,
            'credit' => true,
            'credit_due_date' => now()->addDays(15)->toDateString(),
            'items' => [['warehouse_id' => $ctx['warehouse']->id, 'product_id' => $ctx['product']->id, 'quantity' => $quantity]],
            'payments' => $payments,
        ])->assertCreated();

        return ['order_id' => (int) $response->json('data.id'), 'sale_id' => (int) $response->json('data.sale_id'), 'customer_id' => $customer->id];
    }

    private function paidSale(array $ctx, int $sessionId, float $quantity): array
    {
        $response = $this->checkout($ctx, $sessionId, [['method' => 'cash', 'currency' => 'USD', 'amount' => 100 * $quantity]], $quantity)->assertCreated();

        return ['order_id' => (int) $response->json('data.id'), 'sale_id' => (int) $response->json('data.sale_id'), 'customer_id' => (int) $response->json('data.customer_id')];
    }

    private function requestedReturn(array $ctx, int $saleId, float $quantity): int
    {
        $itemId = (int) DB::table('sale_items')->where('sale_id', $saleId)->value('id');

        return (int) $this->actingAs($ctx['cashier'])->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson('/api/sales-returns', ['sale_id' => $saleId, 'items' => [['sale_item_id' => $itemId, 'quantity' => $quantity]]])
            ->assertCreated()->json('data.id');
    }

    private function approvedReturn(array $ctx, int $saleId, float $quantity): int
    {
        $id = $this->requestedReturn($ctx, $saleId, $quantity);
        $this->actingAs($ctx['cashier'])->withHeader('X-Tenant', $ctx['tenant']->slug)->postJson("/api/sales-returns/{$id}/approve")->assertOk();

        return $id;
    }

    private function processReturn(array $ctx, int $returnId, array $data): TestResponse
    {
        return $this->actingAs($ctx['cashier'])->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson("/api/sales-returns/{$returnId}/process", $data);
    }

    private function cashRefund(int $sessionId, float $amount): array
    {
        return [
            'refund_mode' => 'cash',
            'refund_currency' => 'USD',
            'refund_amount' => $amount,
            'refund_method' => 'cash',
            'refund_cash_register_session_id' => $sessionId,
        ];
    }

    private function collect(array $ctx, int $accountId, int $sessionId, array $data): TestResponse
    {
        return $this->actingAs($ctx['cashier'])->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson("/api/accounts-receivable/{$accountId}/payments", array_merge(['cash_register_session_id' => $sessionId], $data));
    }

    private function receivable(int $saleId): AccountsReceivable
    {
        return AccountsReceivable::query()->withoutGlobalScopes()->where('sale_id', $saleId)->firstOrFail();
    }

    private function available(array $ctx): float
    {
        return (float) DB::table('stock_balances')->where('product_id', $ctx['product']->id)->sum('quantity_available');
    }

    private function creditAvailable(array $ctx, int $customerId): float
    {
        return (float) $this->actingAs($ctx['cashier'])->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->getJson("/api/customers/{$customerId}/credit")->assertOk()->json('data.available_base_amount');
    }

    private function checkout(array $ctx, int $sessionId, array $payments, float $quantity = 1): TestResponse
    {
        $this->useTenant($ctx['tenant']);
        $customer = Customer::create(['name' => 'Cliente '.uniqid(), 'document_type' => Customer::DOCUMENT_V, 'document_number' => (string) random_int(100000, 999999)]);

        return $this->actingAs($ctx['cashier'])->withHeader('X-Tenant', $ctx['tenant']->slug)->postJson('/api/pos/checkouts', [
            'cash_register_session_id' => $sessionId,
            'customer_id' => $customer->id,
            'items' => [['warehouse_id' => $ctx['warehouse']->id, 'product_id' => $ctx['product']->id, 'quantity' => $quantity]],
            'payments' => $payments,
        ]);
    }

    private function reverse(array $ctx, int $orderId, int $sessionId, ?string $reference = 'REEMB-1'): TestResponse
    {
        return $this->actingAs($ctx['cashier'])->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson("/api/pos/orders/{$orderId}/reverse", array_filter([
                'type' => 'reversal',
                'reason' => 'Cliente devolvio la compra',
                'cash_register_session_id' => $sessionId,
                'refund_reference' => $reference,
            ], fn ($value) => $value !== null));
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

    private function sessionData(array $ctx, int $sessionId): array
    {
        return $this->actingAs($ctx['cashier'])
            ->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->getJson("/api/cash-register/sessions/{$sessionId}")
            ->assertOk()
            ->json('data');
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
