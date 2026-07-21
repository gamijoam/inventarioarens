<?php

namespace Tests\Feature\Products;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Sync\Services\SyncOutboxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductImageSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_emits_sync_outbox_for_existing_product_images(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Caracas',
            'slug' => 'demo-caracas',
        ]);

        app(TenantManager::class)->set($tenant);
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenant->id);
        }

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'COCOSETE 3',
            'sku' => 'COCOSETE-3',
            'tracking_type' => Product::TRACKING_QUANTITY,
            'sale_currency' => Product::CURRENCY_USD,
            'unit_of_measure' => Product::UNIT_UNIT,
            'is_active' => true,
        ]);

        ProductImage::create([
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'storage_path' => 'products/'.$tenant->id.'/2026/07/cocosete.webp',
            'mime' => 'image/webp',
            'size' => 1234,
            'original_name' => 'cocosete.webp',
            'width' => 800,
            'height' => 600,
            'sha256' => hash('sha256', 'cocosete'),
            'sort' => 0,
            'is_primary' => true,
        ]);

        $this->artisan('images:emit-sync', [
            '--tenant' => 'demo-caracas',
            '--product-sku' => 'COCOSETE-3',
        ])->assertSuccessful();

        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'product.image.uploaded',
            'aggregate_type' => 'product_image',
            'status' => 'pending',
        ]);
    }

    public function test_outbox_reemit_creates_node_scoped_event_even_if_base_event_exists(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Caracas',
            'slug' => 'demo-caracas',
        ]);

        app(TenantManager::class)->set($tenant);

        $outbox = app(SyncOutboxService::class);
        $key = 'product.image.uploaded:product_image:99:123';
        $payload = ['uuid' => 'img-99', 'sha256' => 'abc'];

        $outbox->record(
            eventType: 'product.image.uploaded',
            aggregateType: 'product_image',
            aggregateId: 99,
            payload: $payload,
            idempotencyKey: $key,
        );

        DB::table('sync_nodes')->insert([
            'tenant_id' => $tenant->id,
            'code' => 'local-images',
            'name' => 'Local Images',
            'type' => 'local',
            'status' => 'active',
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $outbox->record(
            eventType: 'product.image.uploaded',
            aggregateType: 'product_image',
            aggregateId: 99,
            payload: $payload,
            idempotencyKey: $key,
        );

        $this->assertSame(2, DB::table('sync_outbox')->where('event_type', 'product.image.uploaded')->count());
        $this->assertDatabaseHas('sync_outbox', [
            'tenant_id' => $tenant->id,
            'event_type' => 'product.image.uploaded',
            'aggregate_type' => 'product_image',
            'target_scope' => 'node',
            'status' => 'pending',
        ]);
    }
}
