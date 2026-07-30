<?php

namespace Tests\Feature\Sync;

use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncLabCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_emits_a_deterministic_customer_event_for_the_sync_lab(): void
    {
        $tenant = Tenant::create(['name' => 'Sync Lab', 'slug' => 'sync-lab']);

        $this->artisan('sync:lab:emit-customer', [
            'tenant' => $tenant->slug,
            'marker' => 'run-0001',
        ])->assertSuccessful();

        $customer = Customer::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame('E2E-RUN-0001', $customer->document_number);
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'customer.updated',
            'aggregate_type' => 'customer',
            'aggregate_id' => $customer->id,
        ]);
    }

    public function test_it_verifies_a_single_customer_and_optional_inbox_evidence(): void
    {
        $tenant = Tenant::create(['name' => 'Sync Lab', 'slug' => 'sync-lab']);
        app(TenantManager::class)->set($tenant);

        try {
            $customer = Customer::query()->create([
                'name' => 'Cliente E2E Sync',
                'document_type' => Customer::DOCUMENT_V,
                'document_number' => 'E2E-RUN-0002',
                'is_generic' => false,
                'is_active' => true,
            ]);
        } finally {
            app(TenantManager::class)->clear();
        }

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) str()->uuid(),
            'origin_node_id' => null,
            'event_type' => 'customer.updated',
            'aggregate_type' => 'customer',
            'aggregate_id' => $customer->id,
            'payload_hash' => 'lab-payload-hash',
            'payload' => json_encode(['document_number' => $customer->document_number]),
            'status' => 'applied',
            'received_at' => now(),
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('sync:lab:verify-customer', [
            'tenant' => $tenant->slug,
            'marker' => 'run-0002',
            '--require-inbox' => true,
        ])->assertSuccessful();
    }
}
