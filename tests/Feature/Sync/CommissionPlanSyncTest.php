<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Commissions\Models\CommissionPlan;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommissionPlanSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_applies_commission_plan_with_local_rate_and_user_mappings_and_timestamps(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa local', 'slug' => 'empresa-local']);
        app(TenantManager::class)->set($tenant);
        $user = User::factory()->create(['email' => 'vendedor@example.test']);
        $tenant->users()->attach($user->id, ['status' => 'active']);
        $rateType = ExchangeRateType::create([
            'code' => 'BCV',
            'name' => 'BCV local',
            'is_default' => true,
            'is_active' => true,
        ]);
        $payload = [
            'id' => 91,
            'name' => 'Vendedores 3%',
            'beneficiary_role' => 'seller',
            'percentage' => '3.0000',
            'conversion_policy' => 'configured_rate',
            'exchange_rate_type_code' => 'BCV',
            'credit_policy' => 'proportional_collections',
            'maturation_days' => 7,
            'allow_self_stacking' => false,
            'is_active' => true,
            'starts_at' => '2026-08-01T00:00:00Z',
            'ends_at' => null,
            'assignments' => [[
                'user_email' => 'vendedor@example.test',
                'is_active' => true,
            ]],
            'created_at' => '2026-08-14T10:00:00Z',
            'updated_at' => '2026-08-14T10:05:00Z',
        ];
        $this->insertInbox($tenant, $payload);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1, $summary['applied']);
        $plan = DB::table('commission_plans')->where('tenant_id', $tenant->id)->where('name', 'Vendedores 3%')->first();
        $this->assertNotNull($plan);
        $this->assertSame($rateType->id, $plan->exchange_rate_type_id);
        $this->assertNotNull($plan->created_at);
        $this->assertNotNull($plan->updated_at);
        $this->assertDatabaseHas('commission_plan_assignments', [
            'tenant_id' => $tenant->id,
            'commission_plan_id' => $plan->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);
    }

    public function test_missing_assigned_user_keeps_event_failed_for_retry(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa local', 'slug' => 'empresa-local']);
        app(TenantManager::class)->set($tenant);
        $payload = [
            'id' => 92,
            'name' => 'Cajeros 1%',
            'beneficiary_role' => 'cashier',
            'percentage' => '1.0000',
            'conversion_policy' => 'sale_snapshot',
            'credit_policy' => 'sale_confirmation',
            'assignments' => [['user_email' => 'ausente@example.test', 'is_active' => true]],
        ];
        $this->insertInbox($tenant, $payload);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1, $summary['failed']);
        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'commission_plan.created',
            'status' => 'failed',
        ]);
        $this->assertDatabaseMissing('commission_plans', [
            'tenant_id' => $tenant->id,
            'name' => 'Cajeros 1%',
        ]);
    }

    public function test_it_applies_commission_entry_by_uuid_with_mapped_sale_item_and_user_email(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa local', 'slug' => 'empresa-local']);
        app(TenantManager::class)->set($tenant);
        $user = User::factory()->create(['email' => 'vendedor@example.test']);
        $tenant->users()->attach($user->id, ['status' => 'active']);
        $plan = CommissionPlan::create([
            'name' => 'Vendedores 3%',
            'beneficiary_role' => 'seller',
            'percentage' => 3,
            'conversion_policy' => 'sale_snapshot',
            'credit_policy' => 'sale_confirmation',
        ]);
        $branch = Branch::create(['code' => 'SYNC-BR', 'name' => 'Principal', 'status' => 'active']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'SYNC-WH', 'name' => 'Almacen', 'status' => 'active']);
        $product = Product::create(['sku' => 'SYNC-SKU', 'name' => 'Producto', 'sale_currency' => 'USD', 'sale_price' => 100]);
        $sale = Sale::create(['status' => 'confirmed', 'created_by' => $user->id, 'total_base_amount' => 100, 'total_local_amount' => 0, 'confirmed_at' => now()]);
        $sale->forceFill(['sync_source_node_code' => 'LOCAL-01', 'sync_source_id' => 50])->save();
        $item = SaleItem::create(['sale_id' => $sale->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 1, 'sale_currency' => 'USD', 'unit_price' => 100, 'total_amount' => 100, 'base_unit_price' => 100, 'base_total_amount' => 100]);
        $item->forceFill(['sync_source_node_code' => 'LOCAL-01', 'sync_source_id' => 51])->save();
        $payload = [
            'entry_uuid' => '26ceda39-6f38-4ce1-96da-44498e0a9734',
            'source_node_code' => 'LOCAL-01',
            'sale_id' => 50,
            'sale_item_id' => 51,
            'beneficiary_email' => 'vendedor@example.test',
            'beneficiary_role' => 'seller',
            'entry_type' => 'earning',
            'plan_name_snapshot' => $plan->name,
            'percentage_snapshot' => '3.0000',
            'sale_currency' => 'USD',
            'source_amount' => '100.0000',
            'eligible_base_amount' => '100.0000',
            'commission_base_amount' => '3.0000',
            'status' => 'pending',
            'earned_at' => '2026-08-14T10:00:00Z',
            'available_at' => '2026-08-21T10:00:00Z',
            'created_at' => '2026-08-14T10:00:00Z',
            'updated_at' => '2026-08-14T10:00:00Z',
        ];
        $this->insertInbox($tenant, $payload, 'commission_entry.created');

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1, $summary['applied']);
        $this->assertDatabaseHas('commission_entries', [
            'tenant_id' => $tenant->id,
            'entry_uuid' => $payload['entry_uuid'],
            'commission_plan_id' => $plan->id,
            'sale_id' => $sale->id,
            'sale_item_id' => $item->id,
            'beneficiary_user_id' => $user->id,
            'commission_base_amount' => '3.0000',
        ]);
        $entry = DB::table('commission_entries')->where('entry_uuid', $payload['entry_uuid'])->first();
        $this->assertNotNull($entry->created_at);
        $this->assertNotNull($entry->updated_at);
    }

    public function test_it_applies_adjustment_approval_and_settlement_without_sale_mappings(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa local', 'slug' => 'empresa-local']);
        app(TenantManager::class)->set($tenant);
        $seller = User::factory()->create(['email' => 'vendedor@example.test']);
        $manager = User::factory()->create(['email' => 'gerente@example.test']);
        $tenant->users()->attach($seller->id, ['status' => 'active']);
        $tenant->users()->attach($manager->id, ['status' => 'active']);
        $entryUuid = '56ceda39-6f38-4ce1-96da-44498e0a9734';
        $settlementUuid = '66ceda39-6f38-4ce1-96da-44498e0a9734';

        $this->insertInbox($tenant, [
            'entry_uuid' => $entryUuid,
            'beneficiary_email' => $seller->email,
            'beneficiary_role' => 'seller',
            'entry_type' => 'adjustment',
            'plan_name_snapshot' => 'Ajuste manual',
            'percentage_snapshot' => '0.0000',
            'sale_currency' => 'USD',
            'source_amount' => '5.0000',
            'eligible_base_amount' => '5.0000',
            'commission_base_amount' => '5.0000',
            'status' => 'available',
            'adjustment_reason' => 'Bono auditado',
            'earned_at' => '2026-08-14T10:00:00Z',
            'available_at' => '2026-08-14T10:00:00Z',
            'created_at' => '2026-08-14T10:00:00Z',
            'updated_at' => '2026-08-14T10:00:00Z',
        ], 'commission_entry.created');
        $this->insertInbox($tenant, [
            'entry_uuids' => [$entryUuid],
            'approved_by_email' => $manager->email,
            'approved_at' => '2026-08-14T11:00:00Z',
            'updated_at' => '2026-08-14T11:00:00Z',
        ], 'commission_entries.approved');
        $this->insertInbox($tenant, [
            'settlement_uuid' => $settlementUuid,
            'beneficiary_email' => $seller->email,
            'paid_by_email' => $manager->email,
            'entry_uuids' => [$entryUuid],
            'status' => 'paid',
            'payment_currency' => 'USD',
            'total_base_amount' => '5.0000',
            'total_local_amount' => '0.0000',
            'payment_amount' => '5.0000',
            'exchange_rate_type_code' => null,
            'exchange_rate' => null,
            'reference' => 'SYNC-001',
            'notes' => null,
            'paid_at' => '2026-08-14T12:00:00Z',
            'created_at' => '2026-08-14T12:00:00Z',
            'updated_at' => '2026-08-14T12:00:00Z',
        ], 'commission_settlement.created');

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(3, $summary['applied']);
        $entry = DB::table('commission_entries')->where('entry_uuid', $entryUuid)->first();
        $this->assertNotNull($entry);
        $this->assertNull($entry->sale_id);
        $this->assertNull($entry->sale_item_id);
        $this->assertSame('Bono auditado', $entry->adjustment_reason);
        $this->assertSame('paid', $entry->status);
        $this->assertNotNull($entry->approved_at);
        $settlement = DB::table('commission_settlements')->where('settlement_uuid', $settlementUuid)->first();
        $this->assertNotNull($settlement);
        $this->assertNotNull($settlement->created_at);
        $this->assertNotNull($settlement->updated_at);
        $this->assertDatabaseHas('commission_settlement_items', [
            'tenant_id' => $tenant->id,
            'commission_settlement_id' => $settlement->id,
            'commission_entry_id' => $entry->id,
            'commission_base_amount' => '5.0000',
        ]);

        $this->insertInbox($tenant, [
            'entry_uuids' => [$entryUuid],
            'approved_by_email' => $manager->email,
            'approved_at' => '2026-08-14T11:00:00Z',
            'updated_at' => '2026-08-14T13:00:00Z',
        ], 'commission_entries.approved');
        $retry = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1, $retry['applied']);
        $this->assertSame('paid', DB::table('commission_entries')->where('entry_uuid', $entryUuid)->value('status'));
    }

    private function insertInbox(Tenant $tenant, array $payload, string $eventType = 'commission_plan.created'): void
    {
        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => $eventType,
            'aggregate_type' => 'commission_plan',
            'aggregate_id' => $payload['id'] ?? 93,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
