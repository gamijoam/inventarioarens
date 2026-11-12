<?php

namespace Tests\Feature\Currency;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Services\AccountsPayableService;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\FinancialAdjustments\Models\FinancialAdjustment;
use App\Modules\FinancialAdjustments\Services\FinancialAdjustmentService;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * La tasa de un cobro, pago o ajuste la fija el servidor con la tasa oficial
 * vigente del tipo de tasa; una tasa distinta enviada por el cliente se rechaza
 * (auditoria 2026-11-11, hallazgo A1).
 */
class SettlementRateValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ExchangeRateType $rateType;

    public function test_receivable_collection_with_a_made_up_rate_is_rejected(): void
    {
        $account = $this->receivable(200);

        $this->assertRejectedRate(fn () => app(AccountsReceivableService::class)->registerPayment($account, $this->user, [
            'payment_currency' => 'VES',
            'amount' => 200,
            'exchange_rate_type_id' => $this->rateType->id,
            'exchange_rate' => 1,
        ]));

        $account->refresh();
        $this->assertEqualsWithDelta(200.0, (float) $account->balance_base_amount, 0.0001, 'Una deuda de $200 no se salda con Bs 200 a "tasa 1".');
        $this->assertSame(0, $account->payments()->count());
    }

    public function test_receivable_collection_with_the_official_rate_is_accepted(): void
    {
        $account = $this->receivable(200);

        $payment = app(AccountsReceivableService::class)->registerPayment($account, $this->user, [
            'payment_currency' => 'VES',
            'amount' => 10000,
            'exchange_rate_type_id' => $this->rateType->id,
            'exchange_rate' => 100,
        ]);

        $this->assertEqualsWithDelta(100.0, (float) $payment->amount_base, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $payment->exchange_rate, 0.0001);
        $this->assertSame('BCV', $payment->exchange_rate_type_code);
    }

    public function test_receivable_collection_without_rate_uses_the_official_rate(): void
    {
        $account = $this->receivable(200);

        $payment = app(AccountsReceivableService::class)->registerPayment($account, $this->user, [
            'payment_currency' => 'VES',
            'amount' => 5000,
        ]);

        $this->assertEqualsWithDelta(50.0, (float) $payment->amount_base, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $payment->exchange_rate, 0.0001);
    }

    public function test_usd_collection_without_rate_type_keeps_double_entry_with_the_default_rate(): void
    {
        $account = $this->receivable(200);

        $payment = app(AccountsReceivableService::class)->registerPayment($account, $this->user, [
            'payment_currency' => 'USD',
            'amount' => 30,
        ]);

        $this->assertEqualsWithDelta(30.0, (float) $payment->amount_base, 0.0001);
        $this->assertEqualsWithDelta(3000.0, (float) $payment->amount_local, 0.0001, 'El equivalente en Bs no puede quedar en 0.');
        $this->assertSame('BCV', $payment->exchange_rate_type_code);
    }

    public function test_usd_collection_with_a_made_up_rate_is_rejected(): void
    {
        $account = $this->receivable(200);

        $this->assertRejectedRate(fn () => app(AccountsReceivableService::class)->registerPayment($account, $this->user, [
            'payment_currency' => 'USD',
            'amount' => 30,
            'exchange_rate' => 7,
        ]));
    }

    public function test_payable_payment_with_a_made_up_rate_is_rejected(): void
    {
        $account = $this->payable(100);

        $this->assertRejectedRate(fn () => app(AccountsPayableService::class)->registerPayment($account, $this->user, [
            'payment_currency' => 'VES',
            'amount' => 3000,
            'exchange_rate_type_id' => $this->rateType->id,
            'exchange_rate' => 30,
        ]));

        $this->assertEqualsWithDelta(100.0, (float) $account->refresh()->balance_base_amount, 0.0001);
    }

    public function test_payable_payment_with_the_official_rate_is_accepted(): void
    {
        $account = $this->payable(100);

        $payment = app(AccountsPayableService::class)->registerPayment($account, $this->user, [
            'payment_currency' => 'VES',
            'amount' => 5000,
            'exchange_rate_type_id' => $this->rateType->id,
        ]);

        $this->assertEqualsWithDelta(50.0, (float) $payment->amount_base, 0.0001);
    }

    public function test_financial_adjustment_with_a_made_up_rate_is_rejected(): void
    {
        $account = $this->receivable(200);

        $this->assertRejectedRate(fn () => app(FinancialAdjustmentService::class)->create($this->user, [
            'account_type' => FinancialAdjustment::ACCOUNT_RECEIVABLE,
            'account_id' => $account->id,
            'currency' => 'VES',
            'amount' => 200,
            'exchange_rate_type_id' => $this->rateType->id,
            'exchange_rate' => 1,
            'reason' => 'Descuento',
        ]));

        $this->assertEqualsWithDelta(200.0, (float) $account->refresh()->balance_base_amount, 0.0001);
    }

    public function test_rate_type_without_an_active_rate_cannot_validate_a_given_rate(): void
    {
        $account = $this->receivable(200);
        $other = ExchangeRateType::create(['name' => 'Tienda', 'code' => 'TIENDA']);

        $message = $this->assertRejectedRate(fn () => app(AccountsReceivableService::class)->registerPayment($account, $this->user, [
            'payment_currency' => 'VES',
            'amount' => 100,
            'exchange_rate_type_id' => $other->id,
            'exchange_rate' => 120,
        ]));
        $this->assertStringContainsString('No hay una tasa oficial vigente', $message);
    }

    private function assertRejectedRate(callable $operation): string
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('exchange_rate', $exception->errors());

            return $exception->errors()['exchange_rate'][0];
        }

        $this->fail('Se esperaba el rechazo de la tasa.');
    }

    private function receivable(float $amount): AccountsReceivable
    {
        $sale = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => $amount,
            'total_local_amount' => $amount * 100,
        ]);

        return app(AccountsReceivableService::class)->createForSale($sale);
    }

    private function payable(float $amount): AccountsPayable
    {
        $supplier = Supplier::create(['name' => 'Proveedor', 'document_type' => Supplier::DOCUMENT_J, 'document_number' => '123456789']);

        $order = PurchaseOrder::create(['status' => 'confirmed', 'document_number' => 'PO-'.uniqid(), 'total_base_amount' => $amount, 'total_local_amount' => $amount * 100]);

        return AccountsPayable::create([
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $order->id,
            'status' => 'pending',
            'document_number' => 'CXP-'.uniqid(),
            'currency' => 'USD',
            'original_base_amount' => $amount,
            'original_local_amount' => $amount * 100,
            'balance_base_amount' => $amount,
            'balance_local_amount' => $amount * 100,
            'opened_at' => now(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Tasas', 'slug' => 'tasas-'.uniqid()]);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $this->user = User::factory()->create();
        $this->user->tenants()->attach($tenant, ['status' => 'active']);
        $this->rateType = ExchangeRateType::create(['name' => 'BCV', 'code' => 'BCV', 'is_default' => true]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $this->rateType->id,
            'rate' => 100,
            'effective_at' => now()->subMinute(),
            'is_active' => true,
        ]);
    }
}
