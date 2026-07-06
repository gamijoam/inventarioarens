<?php

namespace Tests\Feature\Sync;

use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncEventApplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_applies_product_price_events_without_crossing_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        app(TenantManager::class)->set($tenantA);

        $now = now();
        $productA = $this->product($tenantA, 'SKU-001', 'Producto A', '10.0000');
        $listA = $this->priceList($tenantA, 'DETAL');
        $productB = $this->product($tenantB, 'SKU-001', 'Producto B', '99.0000');
        $listB = $this->priceList($tenantB, 'DETAL');

        DB::table('product_prices')->insert([
            [
                'tenant_id' => $tenantA->id,
                'product_id' => $productA,
                'price_list_id' => $listA,
                'price' => '10.0000',
                'currency' => 'USD',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'tenant_id' => $tenantB->id,
                'product_id' => $productB,
                'price_list_id' => $listB,
                'price' => '99.0000',
                'currency' => 'USD',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenantA->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'product_price.updated',
            'aggregate_type' => 'product_price',
            'aggregate_id' => null,
            'payload_hash' => 'hash',
            'payload' => json_encode([
                'sku' => 'SKU-001',
                'price_list_code' => 'DETAL',
                'price' => '15.5000',
                'currency' => 'USD',
            ]),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenantA);

        $this->assertSame(1, $summary['applied']);
        $this->assertDatabaseHas('product_prices', [
            'tenant_id' => $tenantA->id,
            'product_id' => $productA,
            'price_list_id' => $listA,
            'price' => '15.5000',
        ]);
        $this->assertDatabaseHas('product_prices', [
            'tenant_id' => $tenantB->id,
            'product_id' => $productB,
            'price_list_id' => $listB,
            'price' => '99.0000',
        ]);
    }

    public function test_it_marks_invalid_events_as_failed_with_spanish_message(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Falla', 'slug' => 'empresa-falla']);
        app(TenantManager::class)->set($tenant);

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'product_price.updated',
            'aggregate_type' => 'product_price',
            'payload_hash' => 'hash',
            'payload' => json_encode([
                'sku' => 'NO-EXISTE',
                'price_list_code' => 'DETAL',
                'price' => '15.5000',
            ]),
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenant);

        $this->assertSame(1, $summary['failed']);
        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'product_price.updated',
            'status' => 'failed',
        ]);
        $this->assertStringContainsString('No se encontro el producto', DB::table('sync_inbox')->where('tenant_id', $tenant->id)->value('last_error'));
    }

    private function product(Tenant $tenant, string $sku, string $name, string $price): int
    {
        return (int) DB::table('products')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'sku' => $sku,
            'tracking_type' => 'quantity',
            'base_price' => $price,
            'sale_currency' => 'USD',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function priceList(Tenant $tenant, string $code): int
    {
        return (int) DB::table('price_lists')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => $code,
            'code' => $code,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
