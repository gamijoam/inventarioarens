<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductImageVariant;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Coverage del flujo "Descargar imagen desde una URL" (opcion B):
 *  - Happy path: URL remota valida se descarga y se guarda como ProductImage
 *    con 3 variantes + outbox sync.
 *  - La URL falla (HTTP 500) -> 422 con mensaje en espanol.
 *  - La URL no es una imagen -> 422.
 *  - Validacion: URL invalida -> 422.
 *  - Permission gate: sin products.update -> 403/422.
 */
class ProductImageFromUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_download_image_from_url_into_gallery(): void
    {
        [$tenant, $user] = $this->seedTenantWithOwner();
        $product = $this->seedProduct($tenant);
        Storage::fake('product-images');

        Http::fake([
            'https://cdn.example.com/foto.webp' => Http::response(
                $this->jpegBytes(800, 600),
                200,
                ['Content-Type' => 'image/webp']
            ),
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/products/{$product->id}/images/from-url", [
                'url' => 'https://cdn.example.com/foto.webp',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.width', 800);

        // Http llamo a la URL remota.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://cdn.example.com/foto.webp');

        // Se persistio una imagen con sus 3 variantes.
        $imageId = $response->json('data.id');
        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->count());
        $this->assertSame(3, ProductImageVariant::query()->where('product_image_id', $imageId)->count());

        // Emite el evento sync como cualquier upload.
        $this->assertDatabaseHas('sync_outbox', [
            'event_type' => 'product.image.uploaded',
            'aggregate_type' => 'product_image',
        ]);
    }

    public function test_failed_remote_url_returns_422(): void
    {
        [$tenant, $user] = $this->seedTenantWithOwner();
        $product = $this->seedProduct($tenant);
        Storage::fake('product-images');

        Http::fake([
            'https://cdn.example.com/rota.jpg' => Http::response('', 500),
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/products/{$product->id}/images/from-url", [
                'url' => 'https://cdn.example.com/rota.jpg',
            ])
            ->assertStatus(422);

        $this->assertSame(0, ProductImage::query()->where('product_id', $product->id)->count());
    }

    public function test_non_image_url_returns_422(): void
    {
        [$tenant, $user] = $this->seedTenantWithOwner();
        $product = $this->seedProduct($tenant);
        Storage::fake('product-images');

        Http::fake([
            'https://cdn.example.com/not-image.txt' => Http::response('hola mundo', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/products/{$product->id}/images/from-url", [
                'url' => 'https://cdn.example.com/not-image.txt',
            ])
            ->assertStatus(422);

        $this->assertSame(0, ProductImage::query()->where('product_id', $product->id)->count());
    }

    public function test_invalid_url_returns_422(): void
    {
        [$tenant, $user] = $this->seedTenantWithOwner();
        $product = $this->seedProduct($tenant);

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/products/{$product->id}/images/from-url", [
                'url' => 'no-es-una-url',
            ])
            ->assertStatus(422);

        $this->assertSame(0, ProductImage::query()->where('product_id', $product->id)->count());
    }

    public function test_user_without_update_permission_cannot_download_from_url(): void
    {
        [$tenant, $user] = $this->seedTenantWithoutUpdatePermission();
        $product = $this->seedProduct($tenant);

        Http::fake();

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/products/{$product->id}/images/from-url", [
                'url' => 'https://cdn.example.com/foto.webp',
            ]);

        $this->assertContains($response->getStatusCode(), [403, 422]);
        Http::assertNothingSent();
        $this->assertSame(0, ProductImage::query()->where('product_id', $product->id)->count());
    }

    // ---- Helpers ----

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function seedTenantWithOwner(string $slug = 'telefonos-demo'): array
    {
        $tenancy = app(TenantManager::class);

        $tenant = Tenant::firstOrCreate(
            ['slug' => $slug],
            ['name' => 'Telefonos Demo']
        );
        $tenancy->set($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::firstOrCreate(
            ['email' => 'owner@demo.test'],
            [
                'name' => 'Owner User',
                'password' => bcrypt('secret'),
                'is_platform_admin' => false,
            ]
        );
        if (! $tenant->users()->where('users.id', $user->id)->exists()) {
            $tenant->users()->attach($user, ['status' => 'active']);
        }

        $ownerRole = Role::firstOrCreate(
            ['name' => 'Owner', 'guard_name' => 'web', 'tenant_id' => $tenant->id],
        );
        foreach (BasePermissions::PERMISSIONS as $permName) {
            $perm = Permission::firstOrCreate([
                'name' => $permName,
                'guard_name' => 'web',
            ]);
            if (! $ownerRole->hasPermissionTo($perm)) {
                $ownerRole->givePermissionTo($perm);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (! $user->hasRole('Owner')) {
            $user->assignRole('Owner');
        }

        return [$tenant, $user];
    }

    private function seedTenantWithoutUpdatePermission(): array
    {
        [$tenant, $user] = $this->seedTenantWithOwner();
        setPermissionsTeamId($tenant->id);

        $ownerRole = Role::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'Owner')
            ->where('guard_name', 'web')
            ->first();

        $updatePerm = Permission::where('name', 'products.update')->first();
        if ($updatePerm) {
            $ownerRole->permissions()->detach($updatePerm->id);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$tenant, $user];
    }

    private function seedProduct(Tenant $tenant): Product
    {
        return Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Producto Test',
            'sku' => 'TEST-'.uniqid(),
            'tracking_type' => Product::TRACKING_QUANTITY,
            'sale_currency' => Product::CURRENCY_USD,
            'unit_of_measure' => Product::UNIT_UNIT,
            'is_active' => true,
        ]);
    }

    /**
     * Genera los bytes de un JPEG real para simular la respuesta remota.
     */
    private function jpegBytes(int $w, int $h): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_web_');
        $im = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($im, 50, 100, 200);
        imagefill($im, 0, 0, $bg);
        imagejpeg($im, $tmp, 85);
        imagedestroy($im);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
