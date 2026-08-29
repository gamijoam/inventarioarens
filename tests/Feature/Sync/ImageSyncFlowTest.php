<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductImageService;
use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Sync\Services\SyncInitialSnapshotService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Verifica el flujo de sync de imagenes de producto:
 *  - El cloud_url del evento apunta a la base publica de la NUBE (SYNC_PUBLIC_BASE),
 *    no al APP_URL del nodo local (antes http://localhost -> la nube no podia
 *    descargar).
 *  - El endpoint POST /api/sync/images guarda el binario en la nube.
 *  - El applier aplica product.image.uploaded en el nodo destino.
 */
class ImageSyncFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('sync.transport', 'web');
    }

    private function setupTenant(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        $user->givePermissionTo('sync.transport');

        return [$tenant, $user];
    }

    private function seedProduct(Tenant $tenant): Product
    {
        return Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Producto Imagen',
            'sku' => 'IMG-'.uniqid(),
            'tracking_type' => Product::TRACKING_QUANTITY,
            'sale_currency' => Product::CURRENCY_USD,
            'unit_of_measure' => Product::UNIT_UNIT,
            'is_active' => true,
        ]);
    }

    private function fakeJpegUpload(int $w = 400, int $h = 300): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_jpg_');
        $im = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($im, 50, 100, 200);
        imagefill($im, 0, 0, $bg);
        imagejpeg($im, $tmp, 85);
        imagedestroy($im);

        return [
            'image' => new UploadedFile($tmp, 'demo.jpg', 'image/jpeg', null, true),
        ];
    }

    public function test_cloud_url_uses_sync_public_base_not_local_app_url(): void
    {
        [$tenant, $user] = $this->setupTenant();
        config(['services.sync.public_base' => 'https://app.miinventariofacil.com']);
        config(['app.url' => 'http://localhost']);
        Storage::fake('product-images');

        $product = $this->seedProduct($tenant);
        $service = app(ProductImageService::class);
        $image = $service->upload($product, $this->fakeJpegUpload()['image'], null, $user);

        $payload = json_decode((string) DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'product.image.uploaded')
            ->value('payload'), true);

        $this->assertStringStartsWith('https://app.miinventariofacil.com/storage/', $payload['cloud_url'] ?? '');
        $this->assertStringNotContainsString('localhost', $payload['cloud_url'] ?? '');
        $this->assertSame($image->uuid, $payload['uuid'] ?? null);
        $this->assertNotEmpty($payload['variants'] ?? []);
    }

    public function test_sync_images_endpoint_stores_binary_on_cloud(): void
    {
        [$tenant, $user] = $this->setupTenant();
        $product = $this->seedProduct($tenant);
        Storage::fake('product-images');

        $file = $this->fakeJpegUpload()['image'];
        $bytes = file_get_contents($file->getPathname());
        $sha256 = hash('sha256', $bytes);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->post('/api/sync/images', [
                'uuid' => (string) Str::uuid(),
                'product_sku' => $product->sku,
                'variant' => 'original',
                'sha256' => $sha256,
                'image' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $cloudUrl = $response->json('data.cloud_url');
        $this->assertStringStartsWith('http://localhost/storage/', $cloudUrl);

        Storage::disk('product-images')->assertExists($response->json('data.storage_path'));
    }

    public function test_applier_applies_product_image_uploaded(): void
    {
        [$tenant] = $this->setupTenant();
        $product = $this->seedProduct($tenant);

        $payload = [
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'product_sku' => $product->sku,
            'product_id' => $product->id,
            'cloud_url' => 'https://app.miinventariofacil.com/storage/products/1/2026/08/img.webp',
            'mime' => 'image/webp',
            'size' => 100,
            'width' => 800,
            'height' => 600,
            'sha256' => str_repeat('a', 64),
            'alt' => 'Foto',
            'sort' => 0,
            'is_primary' => true,
            'variants' => [],
        ];

        $now = now();
        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'product.image.uploaded',
            'aggregate_type' => 'product_image',
            'aggregate_id' => 1,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 10);
        $this->assertSame(1, $summary['applied']);

        $this->assertDatabaseHas('product_images', [
            'tenant_id' => $tenant->id,
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'product_id' => $product->id,
            'sha256' => str_repeat('a', 64),
            'is_primary' => true,
        ]);
    }

    public function test_snapshot_includes_product_images(): void
    {
        [$tenant, $user] = $this->setupTenant();
        config(['services.sync.public_base' => 'https://app.miinventariofacil.com']);
        config(['app.url' => 'http://localhost']);
        Storage::fake('product-images');

        $product = $this->seedProduct($tenant);
        $service = app(ProductImageService::class);
        $service->upload($product, $this->fakeJpegUpload()['image'], null, $user);

        $node = DB::table('sync_nodes')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'LOCAL-NEW',
            'name' => 'Nodo Nuevo',
            'type' => 'local',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = app(SyncInitialSnapshotService::class)->queueForNode($tenant, $node, 'INST-1');
        $this->assertGreaterThan(0, $summary['queued']);
        $this->assertGreaterThan(0, $summary['events']['product.image.uploaded']);

        $payload = json_decode((string) DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'product.image.uploaded')
            ->where('target_node_id', $node)
            ->value('payload'), true);

        $this->assertStringStartsWith('https://app.miinventariofacil.com/storage/', $payload['cloud_url'] ?? '');
    }

    public function test_outbox_uses_cloud_storage_path_over_local_tenant_path(): void
    {
        [$tenant, $user] = $this->setupTenant();
        config(['services.sync.public_base' => 'https://app.miinventariofacil.com']);
        config(['app.url' => 'http://localhost']);
        Storage::fake('product-images');

        $product = $this->seedProduct($tenant);
        $service = app(ProductImageService::class);
        $image = $service->upload($product, $this->fakeJpegUpload()['image'], null, $user);

        // Simular la ruta real asignada por la nube (tenant cloud, distinta del
        // storage_path local que usa el tenant local).
        DB::table('product_images')
            ->where('id', $image->id)
            ->update(['cloud_storage_path' => 'products/99/2026/08/real-cloud-path.webp']);

        // Re-emitir un evento (setPrimary) para que use cloud_storage_path.
        $service->setPrimary($image);

        $payload = json_decode((string) DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'product.image.updated')
            ->orderByDesc('id')
            ->value('payload'), true);

        // El evento emitido despues (reorder/update) debe usar la ruta de la
        // nube, no el storage_path local (tenant 1).
        $this->assertStringStartsWith('https://app.miinventariofacil.com/storage/products/99/', $payload['cloud_url'] ?? '');
        $this->assertStringNotContainsString('products/1/', $payload['cloud_url'] ?? '');
    }
}
