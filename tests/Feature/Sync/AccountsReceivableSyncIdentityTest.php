<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La CxC replicada se empareja con la venta por su identidad de sync
 * (nodo de origen + id de origen), nunca por el id local de la venta, que
 * colisiona entre nodos (auditoria 2026-11-11, critico C4).
 */
class AccountsReceivableSyncIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_receivable_event_does_not_touch_cloud_sale_with_colliding_id(): void
    {
        $tenant = $this->tenant();
        $nativeSale = $this->sale(200);
        $nativeAccount = app(AccountsReceivableService::class)->createForSale($nativeSale);
        $replicated = $this->sale(50, 'POS-LOCAL-1', $nativeSale->id);

        $this->enqueue($tenant, 'accounts_receivable.updated', $this->receivablePayload($nativeSale->id, 'POS-LOCAL-1', paid: true));
        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        $native = AccountsReceivable::query()->findOrFail($nativeAccount->id);
        $this->assertSame(AccountsReceivable::STATUS_PENDING, $native->status, 'La CxC de la venta propia de la nube no debe cambiar.');
        $this->assertEqualsWithDelta(200.0, (float) $native->balance_base_amount, 0.0001);

        $remote = AccountsReceivable::query()->where('sale_id', $replicated->id)->firstOrFail();
        $this->assertSame(AccountsReceivable::STATUS_PAID, $remote->status);
        $this->assertEqualsWithDelta(0.0, (float) $remote->balance_base_amount, 0.0001);
        $this->assertNotNull($remote->created_at);
        $this->assertNotNull($remote->updated_at);
    }

    public function test_receivable_for_sale_not_yet_replicated_is_ignored_without_touching_others(): void
    {
        $tenant = $this->tenant();
        $nativeSale = $this->sale(200);
        $nativeAccount = app(AccountsReceivableService::class)->createForSale($nativeSale);

        $this->enqueue($tenant, 'accounts_receivable.created', $this->receivablePayload($nativeSale->id, 'POS-LOCAL-1', paid: true));
        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);

        $this->assertSame(1, $summary['ignored']);
        $this->assertSame(1, AccountsReceivable::query()->count());
        $this->assertSame(AccountsReceivable::STATUS_PENDING, AccountsReceivable::query()->findOrFail($nativeAccount->id)->status);

        // Llega la venta replicada: el siguiente evento de la CxC se aplica a ella.
        $replicated = $this->sale(50, 'POS-LOCAL-1', $nativeSale->id);
        $this->enqueue($tenant, 'accounts_receivable.updated', $this->receivablePayload($nativeSale->id, 'POS-LOCAL-1', paid: true));
        app(SyncEventApplier::class)->applyPending($tenant, 10);

        $this->assertSame(AccountsReceivable::STATUS_PAID, AccountsReceivable::query()->where('sale_id', $replicated->id)->value('status'));
        $this->assertSame(AccountsReceivable::STATUS_PENDING, AccountsReceivable::query()->findOrFail($nativeAccount->id)->status);
    }

    public function test_sale_replicated_from_another_node_is_resolved_by_its_original_identity(): void
    {
        $tenant = $this->tenant();
        $nativeSale = $this->sale(200);
        app(AccountsReceivableService::class)->createForSale($nativeSale);
        // La venta 7 del nodo POS-LOCAL-2 llego a la nube y desde alli a este nodo.
        $replicated = $this->sale(80, 'POS-LOCAL-2', 7);

        $payload = $this->receivablePayload(99, 'NUBE', paid: false);
        $payload['sale_source_node_code'] = 'POS-LOCAL-2';
        $payload['sale_source_id'] = 7;
        $this->enqueue($tenant, 'accounts_receivable.updated', $payload);
        app(SyncEventApplier::class)->applyPending($tenant, 10);

        $this->assertEqualsWithDelta(80.0, (float) AccountsReceivable::query()->where('sale_id', $replicated->id)->value('original_base_amount'), 0.0001);
        $this->assertSame(2, AccountsReceivable::query()->count());
    }

    public function test_receivable_payment_event_applies_to_replicated_sale_once(): void
    {
        $tenant = $this->tenant();
        $nativeSale = $this->sale(200);
        $nativeAccount = app(AccountsReceivableService::class)->createForSale($nativeSale);
        $replicated = $this->sale(50, 'POS-LOCAL-1', $nativeSale->id);

        $payload = [
            'source_node_code' => 'POS-LOCAL-1',
            'sale_id' => $nativeSale->id,
            'receivable' => array_merge($this->receivablePayload($nativeSale->id, 'POS-LOCAL-1', paid: true), ['document_number' => "VENTA-{$nativeSale->id}"]),
            'payment' => ['id' => 31, 'payment_currency' => 'USD', 'amount' => '50.0000', 'amount_base' => '50.0000', 'amount_local' => '25000.0000', 'method' => 'cash', 'reference' => 'COBRO-31'],
        ];
        $this->enqueue($tenant, 'accounts_receivable.payment_registered', $payload);
        $this->enqueue($tenant, 'accounts_receivable.payment_registered', $payload);
        app(SyncEventApplier::class)->applyPending($tenant, 10);

        $remote = AccountsReceivable::query()->where('sale_id', $replicated->id)->firstOrFail();
        $this->assertSame(1, DB::table('accounts_receivable_payments')->where('accounts_receivable_id', $remote->id)->count(), 'El mismo cobro reenviado no se duplica.');
        $this->assertSame(0, DB::table('accounts_receivable_payments')->where('accounts_receivable_id', $nativeAccount->id)->count());
        $this->assertSame(AccountsReceivable::STATUS_PENDING, AccountsReceivable::query()->findOrFail($nativeAccount->id)->status);
    }

    public function test_receivable_payment_for_sale_from_third_node_uses_its_original_identity(): void
    {
        $tenant = $this->tenant();
        $collision = $this->sale(200, 'NUBE', 7);
        $collisionAccount = app(AccountsReceivableService::class)->createForSale($collision);
        $replicated = $this->sale(80, 'POS-LOCAL-2', 7);

        $this->enqueue($tenant, 'accounts_receivable.payment_registered', [
            'source_node_code' => 'NUBE',
            'sale_id' => 99,
            'sale_source_node_code' => 'POS-LOCAL-2',
            'sale_source_id' => 7,
            'receivable' => array_merge($this->receivablePayload(99, 'NUBE', paid: false), ['document_number' => 'VENTA-7']),
            'payment' => ['id' => 44, 'payment_currency' => 'USD', 'amount' => '20.0000', 'amount_base' => '20.0000', 'amount_local' => '10000.0000', 'method' => 'cash', 'reference' => 'COBRO-44'],
        ]);
        app(SyncEventApplier::class)->applyPending($tenant, 10);

        $account = AccountsReceivable::query()->where('sale_id', $replicated->id)->firstOrFail();
        $this->assertSame(1, DB::table('accounts_receivable_payments')->where('accounts_receivable_id', $account->id)->count());
        $this->assertSame(0, DB::table('accounts_receivable_payments')->where('accounts_receivable_id', $collisionAccount->id)->count());
    }

    public function test_receivable_payment_outbox_payload_carries_the_sale_sync_identity(): void
    {
        $this->tenant();
        $replicated = $this->sale(60, 'POS-LOCAL-3', 15);
        $account = app(AccountsReceivableService::class)->createForSale($replicated);
        app(AccountsReceivableService::class)->registerPayment($account, User::query()->firstOrFail(), [
            'payment_currency' => 'USD',
            'amount' => 10,
            'method' => 'transfer',
        ]);

        $payload = json_decode((string) DB::table('sync_outbox')->where('event_type', 'accounts_receivable.payment_registered')->value('payload'), true);
        $this->assertSame('POS-LOCAL-3', $payload['sale_source_node_code']);
        $this->assertSame(15, $payload['sale_source_id']);
    }

    public function test_receivable_outbox_payload_carries_the_sale_sync_identity(): void
    {
        $this->tenant();
        $local = $this->sale(40);
        app(AccountsReceivableService::class)->createForSale($local);
        $replicated = $this->sale(60, 'POS-LOCAL-3', 15);
        app(AccountsReceivableService::class)->createForSale($replicated);

        $payloads = DB::table('sync_outbox')
            ->where('event_type', 'accounts_receivable.created')
            ->orderBy('id')
            ->pluck('payload')
            ->map(fn ($payload) => json_decode($payload, true))
            ->values();

        $this->assertNull($payloads[0]['sale_source_node_code'], 'Venta propia: el nodo receptor usa el nodo emisor.');
        $this->assertSame($local->id, $payloads[0]['sale_source_id']);
        $this->assertSame('POS-LOCAL-3', $payloads[1]['sale_source_node_code']);
        $this->assertSame(15, $payloads[1]['sale_source_id']);
    }

    private function tenant(): Tenant
    {
        $tenant = Tenant::create(['name' => 'Nube', 'slug' => 'nube-'.uniqid()]);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        return $tenant;
    }

    private function sale(float $total, ?string $sourceNode = null, ?int $sourceId = null): Sale
    {
        $sale = Sale::create([
            'status' => Sale::STATUS_CONFIRMED,
            'total_base_amount' => $total,
            'total_local_amount' => $total * 500,
        ]);

        if ($sourceNode !== null) {
            DB::table('sales')->where('id', $sale->id)->update(['sync_source_node_code' => $sourceNode, 'sync_source_id' => $sourceId]);
        }

        return $sale->refresh();
    }

    private function receivablePayload(int $saleId, string $node, bool $paid): array
    {
        return [
            'source_node_code' => $node,
            'document_number' => "VENTA-{$saleId}",
            'sale_id' => $saleId,
            'status' => $paid ? 'paid' : 'pending',
            'currency' => 'USD',
            'original_base_amount' => $paid ? '50.0000' : '80.0000',
            'original_local_amount' => $paid ? '25000.0000' : '40000.0000',
            'collected_base_amount' => $paid ? '50.0000' : '0.0000',
            'collected_local_amount' => $paid ? '25000.0000' : '0.0000',
            'balance_base_amount' => $paid ? '0.0000' : '80.0000',
            'balance_local_amount' => $paid ? '0.0000' : '40000.0000',
        ];
    }

    private function enqueue(Tenant $tenant, string $eventType, array $payload): void
    {
        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => $eventType,
            'aggregate_type' => 'accounts_receivable',
            'aggregate_id' => (int) ($payload['sale_id'] ?? 1),
            'payload_hash' => hash('sha256', (string) json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
