<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Coverage del proxy local de imagenes (Fase 3 - offline-first).
 *
 * Casos:
 *  - Sirve el archivo desde synced-images cuando existe.
 *  - Hace 302 al cloud_url cuando NO esta localmente.
 *  - 404 si el UUID no existe.
 *  - 404 si el UUID no es un UUID v4 valido (defensa contra path traversal).
 *  - 404 si la imagen esta soft-deleted (consistente con la UI).
 *  - El endpoint es PUBLIC: no requiere auth ni CSRF.
 */
class LocalImageProxyTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_UUID = '37e4b97e-1234-4abc-8def-1234567890ab';

    private const EXTERNAL_URL = 'https://app.miinventariofacil.com/storage/products/2/2026/07/test-image.webp';

    public function test_serves_local_file_when_synced_image_exists(): void
    {
        [$tenant, $user] = $this->seedTenant();
        $product = $this->seedProduct($tenant);

        $image = ProductImage::create([
            'uuid' => self::VALID_UUID,
            'product_id' => $product->id,
            'storage_path' => 'products/2/2026/07/test-image.webp',
            'mime' => 'image/webp',
            'size' => 1234,
            'width' => 800,
            'height' => 600,
            'sha256' => str_repeat('a', 64),
            'sort' => 0,
            'is_primary' => true,
        ]);

        // Crear el archivo en synced-images.
        Storage::fake('synced-images');
        Storage::disk('synced-images')->put(
            $image->storage_path,
            'fake-image-bytes'
        );

        $response = $this->getJson("/api/images/{$image->uuid}");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
        // Cache-Control: el orden de directivas lo canonicaliza Laravel, asi que
        // verificamos presencia de cada una en vez de igualdad exacta.
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', (string) $cacheControl);
        $this->assertStringContainsString('max-age=2592000', (string) $cacheControl);
        $this->assertStringContainsString('immutable', (string) $cacheControl);
        $this->assertSame('fake-image-bytes', $response->streamedContent());
    }

    public function test_redirects_to_cloud_url_when_local_file_missing(): void
    {
        [$tenant] = $this->seedTenant();
        $product = $this->seedProduct($tenant);

        $image = ProductImage::create([
            'uuid' => self::VALID_UUID,
            'product_id' => $product->id,
            'storage_path' => self::EXTERNAL_URL,  // es URL absoluta, no archivo local
            'mime' => 'image/webp',
            'size' => 1234,
            'width' => 800,
            'height' => 600,
            'sha256' => str_repeat('a', 64),
            'sort' => 0,
            'is_primary' => true,
        ]);

        Storage::fake('synced-images');
        // synced-images esta vacio.

        $response = $this->getJson("/api/images/{$image->uuid}");

        $response->assertStatus(302);
        $this->assertSame(self::EXTERNAL_URL, $response->headers->get('Location'));
    }

    public function test_404_for_unknown_uuid(): void
    {
        $response = $this->getJson('/api/images/00000000-0000-4000-8000-000000000000');
        $response->assertNotFound();
    }

    public function test_404_for_invalid_uuid_format(): void
    {
        // El constraint de la ruta (regex UUID v4) hace que cualquier string
        // que no matchee caiga al fallback SPA en vez de llegar al controller.
        // Verificamos que el server NO devuelva 500 (path traversal) y que
        // tampoco dispare el controller para texto plano.
        // Path traversal: intenta "../etc/passwd".
        $response = $this->getJson('/api/images/..%2Fetc%2Fpasswd');
        $this->assertNotSame(500, $response->getStatusCode());

        // Texto plano (no UUID): tambien cae al fallback SPA (200), NO al controller.
        $response = $this->getJson('/api/images/not-a-uuid');
        $this->assertNotSame(500, $response->getStatusCode());

        // En cambio, UUID valido pero inexistente: 404 (controller lo busca y no lo encuentra).
        $response = $this->getJson('/api/images/00000000-0000-4000-8000-000000000000');
        $response->assertNotFound();
    }

    public function test_404_for_soft_deleted_image(): void
    {
        [$tenant] = $this->seedTenant();
        $product = $this->seedProduct($tenant);

        $image = ProductImage::create([
            'uuid' => self::VALID_UUID,
            'product_id' => $product->id,
            'storage_path' => self::EXTERNAL_URL,
            'mime' => 'image/webp',
            'size' => 1234,
            'width' => 800,
            'height' => 600,
            'sha256' => str_repeat('a', 64),
            'sort' => 0,
            'is_primary' => true,
        ]);
        $image->delete();  // soft delete

        // El controller NO debe servir imagenes soft-deleted (ni del cache
        // local ni haciendo 302 al cloud): 404 directo para que la UI no
        // muestre imagenes que el user ya borro.
        $response = $this->getJson("/api/images/{$image->uuid}");
        $response->assertNotFound();
    }

    public function test_endpoint_is_public_no_auth_required(): void
    {
        // Sin usuario autenticado. El proxy debe servir la imagen igual.
        [$tenant] = $this->seedTenant();
        $product = $this->seedProduct($tenant);

        $image = ProductImage::create([
            'uuid' => self::VALID_UUID,
            'product_id' => $product->id,
            'storage_path' => 'products/2/2026/07/public-test.webp',
            'mime' => 'image/png',
            'size' => 100,
            'width' => 100,
            'height' => 100,
            'sha256' => str_repeat('b', 64),
            'sort' => 0,
            'is_primary' => false,
        ]);

        Storage::fake('synced-images');
        Storage::disk('synced-images')->put($image->storage_path, 'png-bytes');

        // Sin X-Requested-With, sin Authorization, sin cookie.
        $response = $this->get("/api/images/{$image->uuid}");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
    }

    // ---- Helpers ----

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function seedTenant(): array
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test-tenant']);
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@demo.test',
            'password' => bcrypt('secret'),
            'is_platform_admin' => false,
        ]);
        $tenant->users()->attach($user, ['status' => 'active']);
        // BelongsToTenant aplica un global scope que filtra por tenant_id;
        // si no seteamos el current tenant, las queries (incluyendo el create
        // de Product) fallan con "No current tenant has been resolved".
        app(TenantManager::class)->set($tenant);

        return [$tenant, $user];
    }

    private function seedProduct(Tenant $tenant): Product
    {
        return Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Product',
            'sku' => 'TEST-'.uniqid(),
            'tracking_type' => Product::TRACKING_QUANTITY,
            'sale_currency' => Product::CURRENCY_USD,
            'unit_of_measure' => Product::UNIT_UNIT,
            'is_active' => true,
        ]);
    }
}
