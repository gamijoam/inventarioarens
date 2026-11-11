<?php

namespace Tests\Feature\CashRegister;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\StockBalance;
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
 * Contrato contable del arqueo y cierre de caja (auditoria 2026-11-11).
 *
 * Cada prueba cubre un hallazgo concreto: sobrepagos, diferencias que mezclaban
 * metodos no fisicos y tasas, Reporte Z construido desde movimientos reales,
 * reversos no efectivo, ajustes negativos y controles de cierre/revision.
 */
class CashRegisterAccountingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_rejects_cash_payment_above_sale_total(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openSession($ctx);

        $this->checkout($ctx, $sessionId, [['method' => 'cash', 'currency' => 'USD', 'amount' => 120]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payments']);

        $this->assertSame(0, PosOrder::query()->count(), 'Un cobro rechazado no debe dejar orden creada.');
        $this->assertSame('0.0000', $this->sessionData($ctx, $sessionId)['expected_cash_usd']);
        $this->assertSame(0, CashRegisterMovement::query()->where('type', CashRegisterMovement::TYPE_POS_PAYMENT)->count());
    }

    public function test_overpayment_message_explains_how_to_register_change(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openSession($ctx);

        $message = $this->checkout($ctx, $sessionId, [['method' => 'cash', 'currency' => 'USD', 'amount' => 120]])
            ->assertStatus(422)
            ->json('errors.payments.0');

        $this->assertStringContainsString('supera el total', $message);
        $this->assertStringContainsString('vuelto', $message);
    }

    public function test_checkout_rejects_duplicated_non_cash_payment(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openSession($ctx);

        $this->checkout($ctx, $sessionId, [
            ['method' => 'other', 'currency' => 'USD', 'amount' => 100],
            ['method' => 'other', 'currency' => 'USD', 'amount' => 100],
        ])->assertStatus(422)->assertJsonValidationErrors(['payments']);

        $this->assertSame(0, PosOrder::query()->count());
    }

    public function test_checkout_accepts_exact_mixed_payment(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openSession($ctx);

        $this->checkout($ctx, $sessionId, [
            ['method' => 'cash', 'currency' => 'USD', 'amount' => 60],
            ['method' => 'cash', 'currency' => 'VES', 'amount' => 20000],
        ])->assertCreated()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID)
            ->assertJsonPath('data.paid_base_amount', '100.0000');
    }

    public function test_checkout_tolerates_one_cent_rounding_in_ves_payment(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 36.5);
        $sessionId = $this->openSession($ctx);

        // 100 USD * 36.5 = 3650 Bs; el POS redondea a centimos y puede quedar
        // apenas por encima del total en base.
        $this->checkout($ctx, $sessionId, [['method' => 'cash', 'currency' => 'VES', 'amount' => 3650.2]])
            ->assertCreated();
    }

    public function test_pending_payments_do_not_count_toward_overpayment(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openSession($ctx);

        $this->checkout($ctx, $sessionId, [
            ['method' => 'cash', 'currency' => 'USD', 'amount' => 100],
            ['method' => 'card', 'currency' => 'USD', 'amount' => 100, 'status' => 'failed'],
        ])->assertCreated();
    }

    public function test_add_payments_rejects_amount_above_remaining_balance(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openSession($ctx);
        $orderId = $this->checkout($ctx, $sessionId, [['method' => 'cash', 'currency' => 'USD', 'amount' => 40]])
            ->assertCreated()
            ->json('data.id');

        $this->addPayments($ctx, $orderId, $sessionId, [['method' => 'cash', 'currency' => 'USD', 'amount' => 80]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payments']);

        $this->addPayments($ctx, $orderId, $sessionId, [['method' => 'cash', 'currency' => 'USD', 'amount' => 60]])
            ->assertOk()
            ->assertJsonPath('data.status', PosOrder::STATUS_PAID);

        $this->assertSame('100.0000', $this->sessionData($ctx, $sessionId)['expected_cash_usd']);
    }

    public function test_difference_base_only_reflects_physical_cash_not_electronic_collections(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openSession($ctx);

        $this->checkout($ctx, $sessionId, [['method' => 'cash', 'currency' => 'USD', 'amount' => 100]])->assertCreated();
        $this->checkout($ctx, $sessionId, [['method' => 'transfer', 'currency' => 'USD', 'amount' => 100]])->assertCreated();

        $close = $this->close($ctx, $sessionId, ['counted_cash_usd' => 100, 'counted_cash_ves' => 0])->assertOk()->json('data');

        $this->assertSame('0.0000', $close['difference_cash_usd']);
        $this->assertSame('0.0000', $close['difference_base_amount'], 'Si el efectivo cuadra, no puede reportarse faltante por cobros electronicos.');
        $this->assertSame('200.0000', $close['expected_base_amount'], 'El total movido del turno conserva todos los metodos.');
    }

    public function test_exchange_rate_change_during_shift_does_not_create_fake_difference(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_VES, 500);
        $sessionId = $this->openSession($ctx);

        $this->checkout($ctx, $sessionId, [['method' => 'cash', 'currency' => 'VES', 'amount' => 50000]])->assertCreated();
        $this->changeRate($ctx, 600);

        $close = $this->close($ctx, $sessionId, ['counted_cash_usd' => 0, 'counted_cash_ves' => 50000])->assertOk()->json('data');

        $this->assertSame('0.0000', $close['difference_cash_ves']);
        $this->assertSame('0.0000', $close['difference_base_amount']);
    }

    public function test_ves_shortage_is_valued_at_closing_rate_in_base_difference(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_VES, 500);
        $sessionId = $this->openSession($ctx);

        $this->checkout($ctx, $sessionId, [['method' => 'cash', 'currency' => 'VES', 'amount' => 50000]])->assertCreated();
        $this->changeRate($ctx, 600);

        $close = $this->close($ctx, $sessionId, [
            'counted_cash_usd' => 5,
            'counted_cash_ves' => 44000,
            'closing_notes' => 'Faltante en bolivares',
        ])->assertOk()->json('data');

        $this->assertSame('5.0000', $close['difference_cash_usd']);
        $this->assertSame('-6000.0000', $close['difference_cash_ves']);
        $this->assertSame('-5.0000', $close['difference_base_amount'], '+5 USD y -6000 Bs a tasa de cierre 600 (-10 USD) = -5 USD.');
    }

    public function test_report_z_collections_come_from_the_movements_of_each_shift(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $first = $this->openSession($ctx);
        $orderId = $this->checkout($ctx, $first, [['method' => 'cash', 'currency' => 'USD', 'amount' => 40]])->json('data.id');
        $this->close($ctx, $first, [
            'counted_cash_usd' => 40,
            'counted_cash_ves' => 0,
            'confirm_open_orders' => true,
        ])->assertOk();

        $second = $this->openSession($ctx);
        $this->addPayments($ctx, $orderId, $second, [['method' => 'cash', 'currency' => 'USD', 'amount' => 60]])->assertOk();
        $this->close($ctx, $second, ['counted_cash_usd' => 60, 'counted_cash_ves' => 0])->assertOk();

        $z1 = $this->reportZ($ctx, $first);
        $z2 = $this->reportZ($ctx, $second);

        $this->assertEqualsWithDelta(40.0, $this->sumPayments($z1), 0.0001, 'El abono cobrado en el turno 1 pertenece al Z del turno 1.');
        $this->assertEqualsWithDelta(60.0, $this->sumPayments($z2), 0.0001, 'El Z del turno 2 solo incluye lo que entro en su gaveta.');
        $this->assertEqualsWithDelta(40.0, $z1['totals']['collected_base_amount'], 0.0001);
        $this->assertEqualsWithDelta(60.0, $z2['totals']['collected_base_amount'], 0.0001);
    }

    public function test_report_z_exposes_cash_reconciliation_and_other_movements(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openSession($ctx, 10);

        $this->checkout($ctx, $sessionId, [['method' => 'cash', 'currency' => 'USD', 'amount' => 100]])->assertCreated();
        $this->checkout($ctx, $sessionId, [['method' => 'card', 'currency' => 'USD', 'amount' => 100]])->assertCreated();
        $this->movement($ctx, $sessionId, ['type' => 'outflow', 'method' => 'cash', 'currency' => 'USD', 'amount' => 15, 'notes' => 'Pago de delivery'])->assertOk();

        $this->close($ctx, $sessionId, ['counted_cash_usd' => 95, 'counted_cash_ves' => 0])->assertOk();
        $z = $this->reportZ($ctx, $sessionId);

        $this->assertEqualsWithDelta(95.0, $z['totals']['expected_cash_usd'], 0.0001, 'Fondo 10 + venta 100 - salida 15.');
        $this->assertEqualsWithDelta(95.0, $z['totals']['counted_cash_usd'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $z['totals']['difference_cash_usd'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $z['totals']['difference_base_amount'], 0.0001);
        $this->assertEqualsWithDelta(100.0, $z['totals']['non_cash_collected_base_amount'], 0.0001);

        $types = collect($z['other_movements'])->pluck('type')->all();
        $this->assertContains('opening', $types);
        $this->assertContains('outflow', $types);

        $html = $this->actingAs($ctx['cashier'])->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->get("/api/cash-register/sessions/{$sessionId}/report-z.ticket.html")
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Efectivo USD esperado', $html);
        $this->assertStringContainsString('Cobros no efectivo', $html);
        $this->assertStringNotContainsString('Esperado USD', $html);
    }

    public function test_reversing_card_sale_records_non_cash_outflow(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openSession($ctx);
        $orderId = $this->checkout($ctx, $sessionId, [['method' => 'card', 'currency' => 'USD', 'amount' => 100]])
            ->assertCreated()
            ->json('data.id');

        $this->reverse($ctx, $orderId, $sessionId, 'REEMB-PUNTO-1')->assertCreated();

        $session = $this->sessionData($ctx, $sessionId);
        $this->assertSame('0.0000', $session['expected_base_amount'], 'El reverso con tarjeta debe descontar el total movido.');
        $this->assertSame('0.0000', $session['expected_cash_usd'], 'Un reverso con tarjeta no toca la gaveta.');

        $this->assertDatabaseHas('cash_register_movements', [
            'cash_register_session_id' => $sessionId,
            'type' => CashRegisterMovement::TYPE_OUTFLOW,
            'method' => 'card',
            'reference' => 'REEMB-PUNTO-1',
            'source_type' => 'sale_reversal',
        ]);

        $this->close($ctx, $sessionId, ['counted_cash_usd' => 0, 'counted_cash_ves' => 0])->assertOk();
        $z = $this->reportZ($ctx, $sessionId);
        $this->assertEqualsWithDelta(0.0, $z['totals']['collected_base_amount'], 0.0001);
        $this->assertEqualsWithDelta(100.0, collect($z['refunds'])->sum('amount_base'), 0.0001);
    }

    public function test_adjustment_can_register_a_shortage_with_negative_amount(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openSession($ctx, 50);

        $this->movement($ctx, $sessionId, ['type' => 'adjustment', 'method' => 'cash', 'currency' => 'USD', 'amount' => -10, 'notes' => 'Billete roto'])
            ->assertOk()
            ->assertJsonPath('data.expected_cash_usd', '40.0000');

        $this->movement($ctx, $sessionId, ['type' => 'adjustment', 'method' => 'cash', 'currency' => 'USD', 'amount' => 0, 'notes' => 'Nada'])
            ->assertStatus(422);
        $this->movement($ctx, $sessionId, ['type' => 'outflow', 'method' => 'cash', 'currency' => 'USD', 'amount' => -10, 'notes' => 'Invalido'])
            ->assertStatus(422);
    }

    public function test_closing_with_zero_count_and_expected_cash_requires_confirmation(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openSession($ctx);
        $this->checkout($ctx, $sessionId, [['method' => 'cash', 'currency' => 'USD', 'amount' => 100]])->assertCreated();

        $this->close($ctx, $sessionId, [
            'counted_cash_usd' => 0,
            'counted_cash_ves' => 0,
            'counting_mode' => CashRegisterSession::COUNTING_BLIND,
            'closing_notes' => null,
        ])->assertStatus(422)->assertJsonValidationErrors(['confirm_zero_count']);

        $this->close($ctx, $sessionId, [
            'counted_cash_usd' => 0,
            'counted_cash_ves' => 0,
            'counting_mode' => CashRegisterSession::COUNTING_BLIND,
            'closing_notes' => null,
            'confirm_zero_count' => true,
        ])->assertOk()->assertJsonPath('data.status', CashRegisterSession::STATUS_CLOSED);
    }

    public function test_closing_with_open_orders_holding_payments_requires_confirmation(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openSession($ctx);
        $this->checkout($ctx, $sessionId, [['method' => 'cash', 'currency' => 'USD', 'amount' => 40]])->assertCreated();

        $this->close($ctx, $sessionId, ['counted_cash_usd' => 40, 'counted_cash_ves' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirm_open_orders']);

        $this->close($ctx, $sessionId, ['counted_cash_usd' => 40, 'counted_cash_ves' => 0, 'confirm_open_orders' => true])
            ->assertOk();
    }

    public function test_approving_a_session_with_cash_difference_requires_review_notes(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openSession($ctx);
        $this->checkout($ctx, $sessionId, [['method' => 'cash', 'currency' => 'USD', 'amount' => 100]])->assertCreated();
        $this->close($ctx, $sessionId, ['counted_cash_usd' => 90, 'counted_cash_ves' => 0, 'closing_notes' => 'Faltan 10'])->assertOk();

        $this->review($ctx, $sessionId, ['status' => CashRegisterSession::REVIEW_APPROVED])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['review_notes']);

        $this->review($ctx, $sessionId, ['status' => CashRegisterSession::REVIEW_APPROVED, 'review_notes' => 'Descontado al cajero'])
            ->assertOk()
            ->assertJsonPath('data.review_status', CashRegisterSession::REVIEW_APPROVED);
    }

    public function test_approving_a_balanced_session_does_not_require_notes(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openSession($ctx);
        $this->checkout($ctx, $sessionId, [['method' => 'cash', 'currency' => 'USD', 'amount' => 100]])->assertCreated();
        $this->close($ctx, $sessionId, ['counted_cash_usd' => 100, 'counted_cash_ves' => 0])->assertOk();

        $this->review($ctx, $sessionId, ['status' => CashRegisterSession::REVIEW_APPROVED])->assertOk();
    }

    public function test_every_cash_movement_is_queued_for_sync_after_its_session(): void
    {
        $ctx = $this->bootContext(Product::CURRENCY_USD, 500);
        $sessionId = $this->openSession($ctx, 10);
        $this->checkout($ctx, $sessionId, [['method' => 'cash', 'currency' => 'USD', 'amount' => 100]])->assertCreated();

        $events = DB::table('sync_outbox')
            ->whereIn('event_type', ['cash.session.opened', 'cash.movement.recorded'])
            ->orderBy('id')
            ->get(['event_type', 'payload']);

        $this->assertSame(
            ['cash.session.opened', 'cash.movement.recorded', 'cash.movement.recorded'],
            $events->pluck('event_type')->all(),
            'La sesion debe viajar antes que sus movimientos.'
        );

        $payload = json_decode($events->last()->payload, true);
        $this->assertSame($sessionId, $payload['session_id']);
        $this->assertSame('pos_payment', $payload['type']);
        $this->assertSame('100.0000', $payload['amount']);
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

    private function checkout(array $ctx, int $sessionId, array $payments): TestResponse
    {
        $this->useTenant($ctx['tenant']);
        $customer = Customer::create([
            'name' => 'Cliente '.uniqid(),
            'document_type' => Customer::DOCUMENT_V,
            'document_number' => (string) random_int(100000, 999999),
        ]);

        return $this->actingAs($ctx['cashier'])
            ->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson('/api/pos/checkouts', [
                'cash_register_session_id' => $sessionId,
                'customer_id' => $customer->id,
                'items' => [[
                    'warehouse_id' => $ctx['warehouse']->id,
                    'product_id' => $ctx['product']->id,
                    'quantity' => 1,
                ]],
                'payments' => $payments,
            ]);
    }

    private function addPayments(array $ctx, int $orderId, int $sessionId, array $payments): TestResponse
    {
        return $this->actingAs($ctx['cashier'])
            ->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson("/api/pos/orders/{$orderId}/payments", [
                'cash_register_session_id' => $sessionId,
                'payments' => $payments,
            ]);
    }

    private function movement(array $ctx, int $sessionId, array $data): TestResponse
    {
        return $this->actingAs($ctx['cashier'])
            ->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson("/api/cash-register/sessions/{$sessionId}/movements", $data);
    }

    private function close(array $ctx, int $sessionId, array $data): TestResponse
    {
        return $this->actingAs($ctx['cashier'])
            ->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->patchJson("/api/cash-register/sessions/{$sessionId}/close", array_merge([
                'counted_base_amount' => $data['counted_cash_usd'] ?? 0,
                'counted_local_amount' => $data['counted_cash_ves'] ?? 0,
                'exchange_rate_type_id' => $ctx['rateType']->id,
                'closing_notes' => 'Arqueo de prueba',
                'counting_mode' => CashRegisterSession::COUNTING_STANDARD,
            ], $data));
    }

    private function review(array $ctx, int $sessionId, array $data): TestResponse
    {
        return $this->actingAs($ctx['cashier'])
            ->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson("/api/cash-register/sessions/{$sessionId}/review", $data);
    }

    private function reverse(array $ctx, int $orderId, int $sessionId, string $reference): TestResponse
    {
        return $this->actingAs($ctx['cashier'])
            ->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->postJson("/api/pos/orders/{$orderId}/reverse", [
                'type' => 'reversal',
                'reason' => 'Cliente devolvio la compra',
                'cash_register_session_id' => $sessionId,
                'refund_reference' => $reference,
            ]);
    }

    private function sessionData(array $ctx, int $sessionId): array
    {
        return $this->actingAs($ctx['cashier'])
            ->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->getJson("/api/cash-register/sessions/{$sessionId}")
            ->assertOk()
            ->json('data');
    }

    private function reportZ(array $ctx, int $sessionId): array
    {
        return $this->actingAs($ctx['cashier'])
            ->withHeader('X-Tenant', $ctx['tenant']->slug)
            ->getJson("/api/cash-register/sessions/{$sessionId}/report-z")
            ->assertOk()
            ->json('data');
    }

    private function sumPayments(array $z): float
    {
        return (float) collect($z['payments'])->sum('amount_base');
    }

    private function changeRate(array $ctx, float $rate): void
    {
        $this->useTenant($ctx['tenant']);
        ExchangeRate::query()->update(['is_active' => false]);
        ExchangeRate::create([
            'exchange_rate_type_id' => $ctx['rateType']->id,
            'rate' => $rate,
            'effective_at' => now(),
            'is_active' => true,
        ]);
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
