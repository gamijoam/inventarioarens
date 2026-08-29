<?php

namespace Tests\Feature\Sync;

use App\Modules\Fiscal\Models\FiscalTaxRate;
use App\Modules\Products\Models\Product;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Sync\Models\SyncNode;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Sync\Services\SyncInitialSnapshotService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromotionSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_applies_promotion_created_event_with_product_sku_components(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Sync Promos', 'slug' => 'sync-promos']);
        app(TenantManager::class)->set($tenant);
        $this->product($tenant, 'PHONE-SYNC', 'Telefono');
        $this->product($tenant, 'CHARGER-SYNC', 'Cargador');
        $taxRate = FiscalTaxRate::create([
            'code' => 'EXENTO-SYNC',
            'name' => 'Exento sincronizado',
            'rate' => 0,
            'category' => FiscalTaxRate::CATEGORY_EXEMPT,
            'is_active' => true,
        ]);
        $now = now();
        $payload = [
            'name' => 'Combo sincronizado',
            'code' => 'SYNC-COMBO',
            'scope' => Promotion::SCOPE_COMBO,
            'allows_combos' => true,
            'fiscal_tax_mode' => Promotion::FISCAL_TAX_MODE_OVERRIDE,
            'fiscal_tax_rate_code' => $taxRate->code,
            'benefit_type' => Promotion::BENEFIT_FIXED_BUNDLE_PRICE,
            'price_currency' => 'USD',
            'payment_currency' => 'VES',
            'price_usd' => '50.0000',
            'priority' => 25,
            'is_active' => true,
            'starts_at' => '2026-08-01T00:00:00Z',
            'ends_at' => '2026-12-31T23:59:59Z',
            'items' => [
                ['product_sku' => 'PHONE-SYNC', 'quantity' => '1.0000', 'sort_order' => 0],
                ['product_sku' => 'CHARGER-SYNC', 'quantity' => '1.0000', 'sort_order' => 1],
            ],
        ];

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'promotion.created',
            'aggregate_type' => 'promotion',
            'aggregate_id' => 91,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1, $summary['applied']);
        $this->assertDatabaseHas('promotions', [
            'tenant_id' => $tenant->id,
            'code' => 'SYNC-COMBO',
            'scope' => Promotion::SCOPE_COMBO,
            'allows_combos' => true,
            'price_usd' => '50.0000',
            'payment_currency' => 'VES',
            'fiscal_tax_mode' => Promotion::FISCAL_TAX_MODE_OVERRIDE,
            'fiscal_tax_rate_id' => $taxRate->id,
        ]);
        $promotionId = DB::table('promotions')
            ->where('tenant_id', $tenant->id)
            ->where('code', 'SYNC-COMBO')
            ->value('id');
        $this->assertDatabaseHas('promotion_items', [
            'tenant_id' => $tenant->id,
            'promotion_id' => $promotionId,
            'product_id' => DB::table('products')->where('tenant_id', $tenant->id)->where('sku', 'PHONE-SYNC')->value('id'),
            'quantity' => '1.0000',
        ]);
        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'promotion.created',
            'status' => 'applied',
        ]);
    }

    public function test_it_syncs_percentage_discount_configuration(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Sync Descuento', 'slug' => 'sync-percent']);
        app(TenantManager::class)->set($tenant);
        $payload = [
            'name' => 'Descuento sincronizado',
            'code' => 'SYNC-25',
            'scope' => Promotion::SCOPE_INVOICE,
            'allows_combos' => true,
            'benefit_type' => Promotion::BENEFIT_PERCENT_DISCOUNT,
            'price_currency' => 'USD',
            'price_usd' => null,
            'discount_percent' => '25.00',
            'priority' => 30,
            'is_active' => true,
            'items' => [],
        ];

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'promotion.created',
            'aggregate_type' => 'promotion',
            'aggregate_id' => 92,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1, $summary['applied']);
        $this->assertDatabaseHas('promotions', [
            'tenant_id' => $tenant->id,
            'code' => 'SYNC-25',
            'benefit_type' => Promotion::BENEFIT_PERCENT_DISCOUNT,
            'scope' => Promotion::SCOPE_INVOICE,
            'allows_combos' => true,
            'discount_percent' => '25.00',
        ]);
        $this->assertDatabaseCount('promotion_items', 0);
    }

    public function test_it_syncs_fixed_discount_configuration(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Sync Fijo', 'slug' => 'sync-fixed']);
        app(TenantManager::class)->set($tenant);
        $this->product($tenant, 'PHONE-FIXED', 'Telefono');
        $payload = [
            'name' => 'Descuento fijo sincronizado',
            'code' => 'SYNC-10',
            'benefit_type' => Promotion::BENEFIT_FIXED_DISCOUNT,
            'price_currency' => 'USD',
            'price_usd' => null,
            'discount_amount_usd' => '10.0000',
            'priority' => 30,
            'is_active' => true,
            'items' => [
                ['product_sku' => 'PHONE-FIXED', 'quantity' => '1.0000', 'sort_order' => 0],
            ],
        ];

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'promotion.created',
            'aggregate_type' => 'promotion',
            'aggregate_id' => 93,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1, $summary['applied']);
        $this->assertDatabaseHas('promotions', [
            'tenant_id' => $tenant->id,
            'code' => 'SYNC-10',
            'benefit_type' => Promotion::BENEFIT_FIXED_DISCOUNT,
            'discount_amount_usd' => '10.0000',
        ]);
    }

    public function test_it_syncs_fixed_item_price_configuration(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Sync Precio', 'slug' => 'sync-item-price']);
        app(TenantManager::class)->set($tenant);
        $this->product($tenant, 'PHONE-ITEM-PRICE', 'Telefono');
        $payload = [
            'name' => 'Precio fijo sincronizado',
            'code' => 'SYNC-30',
            'benefit_type' => Promotion::BENEFIT_FIXED_ITEM_PRICE,
            'price_currency' => 'USD',
            'price_usd' => '30.0000',
            'discount_percent' => null,
            'discount_amount_usd' => null,
            'priority' => 30,
            'is_active' => true,
            'items' => [
                ['product_sku' => 'PHONE-ITEM-PRICE', 'quantity' => '1.0000', 'sort_order' => 0],
            ],
        ];

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'promotion.created',
            'aggregate_type' => 'promotion',
            'aggregate_id' => 94,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1, $summary['applied']);
        $this->assertDatabaseHas('promotions', [
            'tenant_id' => $tenant->id,
            'code' => 'SYNC-30',
            'benefit_type' => Promotion::BENEFIT_FIXED_ITEM_PRICE,
            'price_usd' => '30.0000',
        ]);
    }

    public function test_it_syncs_free_item_configuration(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Sync Gratis', 'slug' => 'sync-free']);
        app(TenantManager::class)->set($tenant);
        $this->product($tenant, 'PHONE-FREE', 'Telefono');
        $payload = [
            'name' => 'Gratis sincronizado',
            'code' => 'SYNC-FREE',
            'benefit_type' => Promotion::BENEFIT_FREE_ITEM,
            'price_currency' => 'USD',
            'price_usd' => null,
            'discount_percent' => null,
            'discount_amount_usd' => null,
            'priority' => 30,
            'is_active' => true,
            'items' => [
                ['product_sku' => 'PHONE-FREE', 'quantity' => '1.0000', 'sort_order' => 0],
            ],
        ];

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'promotion.created',
            'aggregate_type' => 'promotion',
            'aggregate_id' => 95,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1, $summary['applied']);
        $this->assertDatabaseHas('promotions', [
            'tenant_id' => $tenant->id,
            'code' => 'SYNC-FREE',
            'benefit_type' => Promotion::BENEFIT_FREE_ITEM,
        ]);
    }

    public function test_it_syncs_buy_x_get_y_roles(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Sync Buy Get', 'slug' => 'sync-buy-get']);
        app(TenantManager::class)->set($tenant);
        $this->product($tenant, 'PHONE-BUY', 'Telefono');
        $this->product($tenant, 'CHARGER-GET', 'Cargador');
        $payload = [
            'name' => 'Compra y recibe sincronizado',
            'code' => 'SYNC-BUY-GET',
            'benefit_type' => Promotion::BENEFIT_BUY_X_GET_Y,
            'price_currency' => 'USD',
            'price_usd' => null,
            'discount_percent' => null,
            'discount_amount_usd' => null,
            'priority' => 30,
            'is_active' => true,
            'items' => [
                ['product_sku' => 'PHONE-BUY', 'quantity' => '1.0000', 'item_role' => 'trigger', 'sort_order' => 0],
                ['product_sku' => 'CHARGER-GET', 'quantity' => '1.0000', 'item_role' => 'reward', 'sort_order' => 1],
            ],
        ];

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'promotion.created',
            'aggregate_type' => 'promotion',
            'aggregate_id' => 96,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1, $summary['applied']);
        $promotionId = DB::table('promotions')->where('tenant_id', $tenant->id)->where('code', 'SYNC-BUY-GET')->value('id');
        $this->assertDatabaseHas('promotion_items', [
            'tenant_id' => $tenant->id,
            'promotion_id' => $promotionId,
            'item_role' => 'reward',
        ]);
    }

    public function test_initial_snapshot_includes_promotions_and_components(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Snapshot Promos', 'slug' => 'snapshot-promos']);
        app(TenantManager::class)->set($tenant);
        $product = $this->product($tenant, 'PHONE-SNAPSHOT', 'Telefono');
        $promotion = Promotion::create([
            'name' => 'Combo snapshot',
            'code' => 'SNAPSHOT-COMBO',
            'scope' => Promotion::SCOPE_COMBO,
            'allows_combos' => true,
            'benefit_type' => Promotion::BENEFIT_FIXED_BUNDLE_PRICE,
            'price_currency' => 'USD',
            'price_usd' => 50,
            'is_active' => true,
        ]);
        $promotion->items()->create(['product_id' => $product->id, 'quantity' => 1, 'sort_order' => 0]);
        $node = SyncNode::create([
            'code' => 'POS-SNAPSHOT',
            'name' => 'POS Snapshot',
            'type' => 'local',
            'status' => 'active',
        ]);

        $summary = app(SyncInitialSnapshotService::class)->queueForNode($tenant, $node->id, 'POS-SNAPSHOT');

        $this->assertSame(1, $summary['events']['promotion.created']);
        $event = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'promotion.created')
            ->first();
        $this->assertNotNull($event);
        $payload = json_decode($event->payload, true);
        $this->assertSame('SNAPSHOT-COMBO', $payload['code']);
        $this->assertSame(Promotion::SCOPE_COMBO, $payload['scope']);
        $this->assertTrue($payload['allows_combos']);
    }

    public function test_initial_snapshot_clears_pending_snapshots_from_previous_installations(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Snapshot Limpio', 'slug' => 'snapshot-limpio']);
        app(TenantManager::class)->set($tenant);
        $node = SyncNode::create([
            'code' => 'POS-SNAPSHOT-CLEAN',
            'name' => 'POS Snapshot Clean',
            'type' => 'local',
            'status' => 'active',
        ]);

        DB::table('sync_outbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'target_node_id' => $node->id,
            'target_scope' => 'node',
            'event_type' => 'product_price.created',
            'aggregate_type' => 'product_price',
            'aggregate_id' => 1,
            'payload' => '{}',
            'occurred_at' => now(),
            'available_at' => now(),
            'status' => 'pending',
            'idempotency_key' => 'initial-snapshot:OLD-INSTALLATION:product_price.created:product_price:1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sync_outbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'target_node_id' => $node->id,
            'target_scope' => 'node',
            'event_type' => 'product.updated',
            'aggregate_type' => 'product',
            'aggregate_id' => 2,
            'payload' => '{}',
            'occurred_at' => now(),
            'available_at' => now(),
            'status' => 'pending',
            'idempotency_key' => 'catalog-event:product:2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SyncInitialSnapshotService::class)->queueForNode($tenant, $node->id, 'NEW-INSTALLATION');

        $this->assertDatabaseMissing('sync_outbox', [
            'tenant_id' => $tenant->id,
            'target_node_id' => $node->id,
            'idempotency_key' => 'initial-snapshot:OLD-INSTALLATION:product_price.created:product_price:1',
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'target_node_id' => $node->id,
            'idempotency_key' => 'catalog-event:product:2',
        ]);
    }

    public function test_initial_snapshot_regeneration_removes_processed_events_with_same_idempotency_key(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Snapshot Regen', 'slug' => 'snapshot-regen']);
        app(TenantManager::class)->set($tenant);
        $node = SyncNode::create([
            'code' => 'POS-SNAPSHOT-REGEN',
            'name' => 'POS Snapshot Regen',
            'type' => 'local',
            'status' => 'active',
        ]);
        $this->branch($tenant, '001', 'Chichiriviche');
        $first = app(SyncInitialSnapshotService::class)->queueForNode($tenant, $node->id, 'SAME-INSTALLATION');

        DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('target_node_id', $node->id)
            ->update(['status' => 'processed', 'processed_at' => now()]);

        $summary = app(SyncInitialSnapshotService::class)->queueForNode($tenant, $node->id, 'SAME-INSTALLATION');

        $this->assertSame($first['queued'], $summary['queued']);
        $this->assertSame(
            1,
            DB::table('sync_outbox')
                ->where('tenant_id', $tenant->id)
                ->where('target_node_id', $node->id)
                ->where('idempotency_key', 'like', 'initial-snapshot:%branch.created:branch:%')
                ->count(),
        );
    }

    private function branch(Tenant $tenant, string $code, string $name): int
    {
        return (int) DB::table('branches')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'code' => $code,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(Tenant $tenant, string $sku, string $name): Product
    {
        app(TenantManager::class)->set($tenant);

        return Product::create([
            'name' => $name,
            'sku' => $sku,
            'base_price' => 20,
            'sale_currency' => Product::CURRENCY_USD,
        ]);
    }
}
