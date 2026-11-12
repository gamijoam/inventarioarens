<?php

namespace Tests\Feature\Currency;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Services\AccountsPayableService;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use App\Modules\Currency\Models\ExchangeDifference;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
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
 * Diferencial cambiario (auditoria 2026-11-11, critico C7 y hallazgo A2):
 * cobros y pagos a una tasa distinta a la del documento registran la ganancia
 * o perdida; la CxP en bolivares se salda por su monto en Bs.
 * Convencion de signo: positivo = ganancia de la empresa, negativo = perdida.
 */
class ExchangeDifferenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private ExchangeRateType $rateType;

    public function test_receivable_collected_in_bolivars_at_a_higher_rate_records_a_gain(): void
    {
        $account = $this->receivable(100, 36);
        $this->setRate(40);

        $payment = app(AccountsReceivableService::class)->registerPayment($account, $this->user, [
            'payment_currency' => 'VES',
            'amount' => 4000,
        ]);

        $this->assertSame(AccountsReceivable::STATUS_PAID, $account->refresh()->status);
        $difference = ExchangeDifference::query()->sole();
        $this->assertSame(ExchangeDifference::ACCOUNT_RECEIVABLE, $difference->account_type);
        $this->assertSame($payment->id, (int) $difference->payment_id);
        $this->assertEqualsWithDelta(36.0, (float) $difference->document_rate, 0.0001);
        $this->assertEqualsWithDelta(40.0, (float) $difference->settlement_rate, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $difference->applied_base_amount, 0.0001);
        $this->assertEqualsWithDelta(400.0, (float) $difference->difference_local_amount, 0.0001, 'Bs 4.000 cobrados frente a Bs 3.600 de la venta.');
        $this->assertEqualsWithDelta(10.0, (float) $difference->difference_base_amount, 0.0001);
        $this->assertSame(ExchangeDifference::KIND_GAIN, $difference->kind);
    }

    public function test_receivable_collected_in_dollars_also_records_the_bolivar_difference(): void
    {
        $account = $this->receivable(100, 36);
        $this->setRate(40);

        app(AccountsReceivableService::class)->registerPayment($account, $this->user, ['payment_currency' => 'USD', 'amount' => 100]);

        $this->assertEqualsWithDelta(400.0, (float) ExchangeDifference::query()->sole()->difference_local_amount, 0.0001);
    }

    public function test_receivable_collected_at_a_lower_rate_records_a_loss(): void
    {
        $account = $this->receivable(100, 40);
        $this->setRate(36);

        app(AccountsReceivableService::class)->registerPayment($account, $this->user, ['payment_currency' => 'VES', 'amount' => 3600]);

        $difference = ExchangeDifference::query()->sole();
        $this->assertEqualsWithDelta(-400.0, (float) $difference->difference_local_amount, 0.0001);
        $this->assertSame(ExchangeDifference::KIND_LOSS, $difference->kind);
    }

    public function test_collection_at_the_document_rate_records_no_difference(): void
    {
        $account = $this->receivable(100, 36);
        $this->setRate(36);

        app(AccountsReceivableService::class)->registerPayment($account, $this->user, ['payment_currency' => 'VES', 'amount' => 3600]);

        $this->assertSame(0, ExchangeDifference::query()->count());
    }

    public function test_bolivar_payable_is_settled_by_its_bolivar_amount_when_the_rate_rises(): void
    {
        $account = $this->payable('VES', 3600, 36);
        $this->setRate(40);

        $payment = app(AccountsPayableService::class)->registerPayment($account, $this->user, ['payment_currency' => 'VES', 'amount' => 3600]);

        $account->refresh();
        $this->assertSame(AccountsPayable::STATUS_PAID, $account->status, 'Una factura de Bs 3.600 pagada con Bs 3.600 queda saldada.');
        $this->assertEqualsWithDelta(0.0, (float) $account->balance_base_amount, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $account->balance_local_amount, 0.0001);
        $this->assertEqualsWithDelta(90.0, (float) $payment->amount_base, 0.0001, 'El pago real equivale a $90 a la tasa del dia.');

        $difference = ExchangeDifference::query()->sole();
        $this->assertSame(ExchangeDifference::ACCOUNT_PAYABLE, $difference->account_type);
        $this->assertEqualsWithDelta(100.0, (float) $difference->applied_base_amount, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $difference->difference_base_amount, 0.0001, 'Se saldo una deuda de $100 con $90.');
        $this->assertSame(ExchangeDifference::KIND_GAIN, $difference->kind);
    }

    public function test_bolivar_payable_exact_payment_is_accepted_when_the_rate_falls(): void
    {
        $account = $this->payable('VES', 3000, 30);
        $this->setRate(25);

        app(AccountsPayableService::class)->registerPayment($account, $this->user, ['payment_currency' => 'VES', 'amount' => 3000]);

        $this->assertSame(AccountsPayable::STATUS_PAID, $account->refresh()->status);
        $difference = ExchangeDifference::query()->sole();
        $this->assertEqualsWithDelta(-20.0, (float) $difference->difference_base_amount, 0.0001, 'Bs 3.000 a 25 son $120 para saldar $100.');
        $this->assertSame(ExchangeDifference::KIND_LOSS, $difference->kind);
    }

    public function test_bolivar_payable_rejects_paying_more_bolivars_than_owed(): void
    {
        $account = $this->payable('VES', 3600, 36);
        $this->setRate(40);

        $this->expectException(ValidationException::class);
        app(AccountsPayableService::class)->registerPayment($account, $this->user, ['payment_currency' => 'VES', 'amount' => 3700]);
    }

    public function test_bolivar_payable_partial_payment_keeps_the_bolivar_balance(): void
    {
        $account = $this->payable('VES', 3600, 36);
        $this->setRate(40);

        app(AccountsPayableService::class)->registerPayment($account, $this->user, ['payment_currency' => 'VES', 'amount' => 1800]);

        $account->refresh();
        $this->assertSame(AccountsPayable::STATUS_PARTIAL, $account->status);
        $this->assertEqualsWithDelta(1800.0, (float) $account->balance_local_amount, 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $account->balance_base_amount, 0.0001, 'El saldo en USD se mantiene a la tasa de la factura.');
    }

    public function test_bolivar_payable_paid_in_installments_closes_without_dollar_residue(): void
    {
        $account = $this->payable('VES', 30, 3);

        foreach ([1, 2, 3] as $installment) {
            app(AccountsPayableService::class)->registerPayment($account->refresh(), $this->user, ['payment_currency' => 'VES', 'amount' => 10]);
        }

        $account->refresh();
        $this->assertSame(AccountsPayable::STATUS_PAID, $account->status, 'Tres pagos de Bs 10 saldan una factura de Bs 30 aunque en USD sumen 9,9999.');
        $this->assertEqualsWithDelta(0.0, (float) $account->balance_base_amount, 0.00001);
        $this->assertEqualsWithDelta(0.0, (float) $account->balance_local_amount, 0.00001);
    }

    public function test_dollar_payable_paid_at_a_higher_rate_records_a_bolivar_loss(): void
    {
        $account = $this->payable('USD', 100, 36);
        $this->setRate(40);

        app(AccountsPayableService::class)->registerPayment($account, $this->user, ['payment_currency' => 'VES', 'amount' => 4000]);

        $this->assertSame(AccountsPayable::STATUS_PAID, $account->refresh()->status);
        $difference = ExchangeDifference::query()->sole();
        $this->assertEqualsWithDelta(-400.0, (float) $difference->difference_local_amount, 0.0001, 'Se pagaron Bs 4.000 por una factura registrada en Bs 3.600.');
        $this->assertSame(ExchangeDifference::KIND_LOSS, $difference->kind);
    }

    public function test_differences_are_listed_with_totals_and_isolated_by_tenant(): void
    {
        $account = $this->receivable(100, 36);
        $this->setRate(40);
        app(AccountsReceivableService::class)->registerPayment($account, $this->user, ['payment_currency' => 'VES', 'amount' => 4000]);
        $payable = $this->payable('USD', 50, 36);
        $this->setRate(40);
        app(AccountsPayableService::class)->registerPayment($payable, $this->user, ['payment_currency' => 'VES', 'amount' => 2000]);

        $response = $this->actingAs($this->user)->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/exchange-differences')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->assertEqualsWithDelta(400.0, (float) $response->json('totals.gain_local_amount'), 0.0001);
        $this->assertEqualsWithDelta(-200.0, (float) $response->json('totals.loss_local_amount'), 0.0001);
        $this->assertEqualsWithDelta(200.0, (float) $response->json('totals.net_local_amount'), 0.0001);

        $other = Tenant::create(['name' => 'Otra', 'slug' => 'otra-'.uniqid()]);
        $outsider = User::factory()->create();
        $outsider->tenants()->attach($other, ['status' => 'active']);
        app(TenantManager::class)->set($other);
        setPermissionsTeamId($other->id);
        $role = Role::findOrCreate('Administrador', 'web');
        $role->syncPermissions(BasePermissions::PERMISSIONS);
        $outsider->assignRole($role);

        $this->actingAs($outsider)->withHeader('X-Tenant', $other->slug)
            ->getJson('/api/exchange-differences')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_listing_requires_finance_report_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->tenants()->attach($this->tenant, ['status' => 'active']);

        $this->actingAs($viewer)->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/exchange-differences')
            ->assertForbidden();
    }

    public function test_difference_is_queued_for_sync_and_applied_once_on_another_node(): void
    {
        $account = $this->receivable(100, 36);
        $this->setRate(40);
        app(AccountsReceivableService::class)->registerPayment($account, $this->user, ['payment_currency' => 'VES', 'amount' => 4000]);

        $event = DB::table('sync_outbox')->where('event_type', 'exchange_difference.recorded')->sole();
        $payload = json_decode($event->payload, true);
        $this->assertSame('receivable', $payload['account_type']);
        $this->assertSame('400.0000', $payload['difference_local_amount']);

        DB::table('exchange_differences')->delete();
        $payload['source_node_code'] = 'POS-LOCAL-1';
        foreach ([1, 2] as $ignored) {
            DB::table('sync_inbox')->insert([
                'tenant_id' => $this->tenant->id,
                'event_uuid' => (string) Str::uuid(),
                'event_type' => 'exchange_difference.recorded',
                'aggregate_type' => 'exchange_difference',
                'aggregate_id' => $payload['difference_id'],
                'payload_hash' => hash('sha256', json_encode($payload)),
                'payload' => json_encode($payload),
                'status' => 'received',
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        app(SyncEventApplier::class)->applyPending($this->tenant, 10);

        $replicated = ExchangeDifference::query()->sole();
        $this->assertSame('POS-LOCAL-1', $replicated->sync_source_node_code);
        $this->assertEqualsWithDelta(400.0, (float) $replicated->difference_local_amount, 0.0001);
        $this->assertNotNull($replicated->created_at);
        $this->assertNotNull($replicated->updated_at);
    }

    private function receivable(float $amountBase, float $documentRate): AccountsReceivable
    {
        $this->setRate($documentRate);
        $sale = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => $amountBase,
            'total_local_amount' => $amountBase * $documentRate,
        ]);

        return app(AccountsReceivableService::class)->createForSale($sale);
    }

    private function payable(string $currency, float $amount, float $documentRate): AccountsPayable
    {
        $this->setRate($documentRate);
        $supplier = Supplier::create(['name' => 'Proveedor '.uniqid(), 'document_type' => Supplier::DOCUMENT_J, 'document_number' => (string) random_int(100000000, 999999999)]);
        $base = $currency === 'VES' ? round($amount / $documentRate, 4) : $amount;
        $local = $currency === 'VES' ? $amount : $amount * $documentRate;
        $order = PurchaseOrder::create(['status' => 'received', 'document_number' => 'PO-'.uniqid(), 'total_base_amount' => $base, 'total_local_amount' => $local]);

        return AccountsPayable::create([
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $order->id,
            'status' => AccountsPayable::STATUS_PENDING,
            'document_number' => 'CXP-'.uniqid(),
            'currency' => $currency,
            'exchange_rate_type_id' => $this->rateType->id,
            'exchange_rate_type_code' => 'BCV',
            'exchange_rate' => $documentRate,
            'original_base_amount' => $base,
            'original_local_amount' => $local,
            'balance_base_amount' => $base,
            'balance_local_amount' => $local,
            'opened_at' => now(),
        ]);
    }

    private function setRate(float $rate): void
    {
        ExchangeRate::query()->update(['is_active' => false]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $this->rateType->id,
            'rate' => $rate,
            'effective_at' => now()->addSeconds(ExchangeRate::query()->count()),
            'is_active' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->tenant = Tenant::create(['name' => 'Diferencial', 'slug' => 'diferencial-'.uniqid()]);
        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);
        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant, ['status' => 'active']);
        $role = Role::findOrCreate('Administrador', 'web');
        $role->syncPermissions(BasePermissions::PERMISSIONS);
        $this->user->assignRole($role);
        $this->rateType = ExchangeRateType::create(['name' => 'BCV', 'code' => 'BCV', 'is_default' => true]);
    }
}
