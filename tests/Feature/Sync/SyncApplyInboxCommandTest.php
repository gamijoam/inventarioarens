<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncApplyInboxCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_applies_received_product_events_for_a_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Valencia',
            'slug' => 'demo-valencia',
        ]);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, ['status' => 'active']);
        $now = now();

        DB::table('products')->insert([
            'tenant_id' => $tenant->id,
            'name' => 'Adaptador Bluetooth',
            'sku' => 'ADP-BT-VAL',
            'tracking_type' => 'quantity',
            'base_price' => '20.0000',
            'sale_currency' => 'USD',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'origin_node_id' => null,
            'event_type' => 'product.updated',
            'aggregate_type' => 'product',
            'aggregate_id' => 67,
            'payload_hash' => hash('sha256', json_encode(['sku' => 'ADP-BT-VAL'])),
            'payload' => json_encode([
                'sku' => 'ADP-BT-VAL',
                'name' => 'Adaptador Bluetooth',
                'tracking_type' => 'quantity',
                'base_price' => '2000.0000',
                'sale_currency' => 'USD',
                'is_active' => true,
            ]),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->artisan('sync:apply-inbox', ['tenant' => 'demo-valencia'])
            ->expectsOutput('Eventos recibidos procesados.')
            ->expectsOutput('Empresa: demo-valencia')
            ->expectsOutput('Aplicados: 1')
            ->expectsOutput('Ignorados: 0')
            ->expectsOutput('Fallidos: 0')
            ->assertSuccessful();

        $this->assertDatabaseHas('products', [
            'tenant_id' => $tenant->id,
            'sku' => 'ADP-BT-VAL',
            'base_price' => '2000.0000',
        ]);
        $this->assertDatabaseHas('sync_inbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'product.updated',
            'status' => 'applied',
        ]);
    }
}
