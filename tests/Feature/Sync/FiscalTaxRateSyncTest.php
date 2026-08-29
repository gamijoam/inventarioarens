<?php

namespace Tests\Feature\Sync;

use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FiscalTaxRateSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_applier_creates_fiscal_tax_rate_with_timestamps(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Sync Fiscal', 'slug' => 'empresa-sync-fiscal']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);

        $event = [
            'event_type' => 'fiscal_tax_rate.created',
            'aggregate_type' => 'fiscal_tax_rate',
            'aggregate_id' => 77,
            'event_uuid' => (string) Str::uuid(),
            'payload' => json_encode([
                'code' => 'IVA16',
                'name' => 'IVA general',
                'rate' => '16.0000',
                'category' => 'taxable',
                'is_active' => true,
            ]),
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $event['id'] = DB::table('sync_inbox')->insertGetId(array_merge($event, [
            'tenant_id' => $tenant->id,
        ]));

        $result = app(SyncEventApplier::class)->applyOne($tenant, $event);

        $this->assertSame('applied', $result);
        $rate = DB::table('fiscal_tax_rates')->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($rate);
        $this->assertSame('IVA16', $rate->code);
        $this->assertSame('taxable', $rate->category);
        $this->assertNotNull($rate->created_at);
        $this->assertNotNull($rate->updated_at);
    }

    public function test_product_applier_resolves_tax_rate_by_code_in_destination_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Producto Sync Fiscal', 'slug' => 'empresa-producto-sync-fiscal']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        DB::table('fiscal_tax_rates')->insert([
            'tenant_id' => $tenant->id,
            'code' => 'IVA16',
            'name' => 'IVA general',
            'rate' => 16,
            'category' => 'taxable',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = json_encode([
            'sku' => 'SYNC-IVA-001',
            'name' => 'Producto sincronizado',
            'fiscal_tax_rate_code' => 'IVA16',
        ]);
        $event = [
            'event_type' => 'product.created',
            'aggregate_type' => 'product',
            'aggregate_id' => 88,
            'event_uuid' => (string) Str::uuid(),
            'payload' => $payload,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $event['id'] = DB::table('sync_inbox')->insertGetId(array_merge($event, [
            'tenant_id' => $tenant->id,
        ]));

        $this->assertSame('applied', app(SyncEventApplier::class)->applyOne($tenant, $event));

        $product = DB::table('products')->where('tenant_id', $tenant->id)->where('sku', 'SYNC-IVA-001')->first();
        $this->assertNotNull($product);
        $this->assertSame($tenant->id, $product->tenant_id);
        $this->assertSame(
            $tenant->id,
            DB::table('fiscal_tax_rates')->where('id', $product->fiscal_tax_rate_id)->value('tenant_id'),
        );
        $this->assertNotNull($product->created_at);
        $this->assertNotNull($product->updated_at);
    }
}
