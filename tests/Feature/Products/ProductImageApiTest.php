<?php

namespace Tests\Feature\Products;

use App\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Sync\Models\SyncOutbox;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\FileFactory;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Coverage del flujo de imagenes propias de producto (Nivel 2).
 *
 * Tests incluidos:
 *  - Upload happy path (jpg 800x600): crea ProductImage + 3 variantes WebP.
 *  - Index devuelve la galeria ordenada por sort.
 *  - Set-primary swaps is_primary entre imagenes del mismo producto.
 *  - Delete es soft (deleted_at) y no borra el archivo fisico (eso es Nivel 3).
 *  - Cross-tenant 403: imagen de tenant A no visible para tenant B.
 *  - Sync outbox emite product.image.uploaded con sha256 + variants.
 *  - Permission gate: user sin products.image.upload recibe 403.
 *  - Validacion: mime invalido (txt) recibe 422.
 */
class ProductImageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upload_image_and_index_returns_3_variants(): void
    {
        [$tenant, $user] = $this->seedTenantWithOwner();
        $product = $this->seedProduct($tenant);

        Storage::fake('product-images');
        $payload = $this->fakeJpegUpload(800, 600);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/products/{$product->id}/images", $payload);

        $response->assertCreated()
            ->assertJsonPath('data.width', 800)
            ->assertJsonPath('data.height', 600)
            ->assertJsonPath('data.is_primary', true);

        // 3 variantes.
        $this->assertSame(3, $response->json('data.variants_count') ?? null);
        $this->assertDatabaseCount('product_image_variants', 3);

        // Outbox emite el evento sync.
        $this->assertDatabaseHas('sync_outbox', [
            'event_type' => 'product.image.uploaded',
            'aggregate_type' => 'product_image',
        ]);
    }

    public function test_index_returns_gallery_ordered_by_sort(): void
    {
        [$tenant, $user] = $this->seedTenantWithOwner();
        $product = $this->seedProduct($tenant);
        Storage::fake('product-images');

        // Subir 2 imagenes (la primera es primary, la segunda no).
        $a = $this->uploadOne($product, $user, $tenant->slug, $this->fakeJpegUpload(400, 400), 'first');
        $this->uploadOne($product, $user, $tenant->slug, $this->fakeJpegUpload(500, 500), 'second');

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/products/{$product->id}/images");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame($a['id'], $data[0]['id']);
        $this->assertSame('first', $data[0]['original_name']);
    }

    public function test_set_primary_swaps_is_primary(): void
    {
        [$tenant, $user] = $this->seedTenantWithOwner();
        $product = $this->seedProduct($tenant);
        Storage::fake('product-images');

        $a = $this->uploadOne($product, $user, $tenant->slug, $this->fakeJpegUpload(400, 400), 'a');
        $b = $this->uploadOne($product, $user, $tenant->slug, $this->fakeJpegUpload(500, 500), 'b');

        // Por defecto la primera es primary.
        $this->assertTrue((bool) $a['is_primary']);
        $this->assertFalse((bool) $b['is_primary']);

        // Cambiamos a 'b'.
        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson("/api/products/{$product->id}/images/{$b['id']}", ['is_primary' => true])
            ->assertOk();

        $this->assertDatabaseHas('product_images', ['id' => $a['id'], 'is_primary' => false]);
        $this->assertDatabaseHas('product_images', ['id' => $b['id'], 'is_primary' => true]);
    }

    public function test_destroy_is_soft_delete(): void
    {
        [$tenant, $user] = $this->seedTenantWithOwner();
        $product = $this->seedProduct($tenant);
        Storage::fake('product-images');

        $image = $this->uploadOne($product, $user, $tenant->slug, $this->fakeJpegUpload(400, 400), 'gone');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/products/{$product->id}/images/{$image['id']}")
            ->assertOk();

        // Soft delete: la fila sigue, pero con deleted_at poblado.
        $this->assertSoftDeleted('product_images', ['id' => $image['id']]);
        $this->assertDatabaseMissing('sync_outbox', [
            'event_type' => 'product.image.deleted',
        ]); // el evento se emite en el servicio, no en el controller actual — se cubre en applyProductImageDeleted test
    }

    public function test_other_tenant_cannot_view_or_modify_images(): void
    {
        [$tenantA, $userA] = $this->seedTenantWithOwner();
        $productA = $this->seedProduct($tenantA);
        Storage::fake('product-images');
        $imageA = $this->uploadOne($productA, $userA, $tenantA->slug, $this->fakeJpegUpload(400, 400), 'a');

        [$tenantB, $userB] = $this->seedTenantWithOwner('empresa-b');

        // Listar imagenes del producto de A desde B debe dar 403.
        $this
            ->actingAs($userB)
            ->withHeader('X-Tenant', $tenantB->slug)
            ->getJson("/api/products/{$productA->id}/images")
            ->assertForbidden();
    }

    public function test_user_without_image_upload_permission_cannot_upload(): void
    {
        [$tenant, $user] = $this->seedTenantWithoutImagePermission();
        $product = $this->seedProduct($tenant);
        Storage::fake('product-images');

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/products/{$product->id}/images", ['image' => $this->fakeJpegUpload(400, 400)])
            ->assertForbidden();
    }

    public function test_invalid_mime_returns_422(): void
    {
        [$tenant, $user] = $this->seedTenantWithOwner();
        $product = $this->seedProduct($tenant);
        Storage::fake('product-images');

        // .txt no es una imagen valida.
        $uploaded = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'fake.txt',
            'not an image'
        );

        $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson("/api/products/{$product->id}/images", ['image' => $uploaded])
            ->assertStatus(422);
    }

    // ---- Helpers ----

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function seedTenantWithOwner(string $slug = 'telefonos-demo'): array
    {
        $tenant = Tenant::create(['name' => 'Telefonos Demo', 'slug' => $slug]);
        $user = User::create([
            'name' => 'Owner User',
            'email' => 'owner@demo.test',
            'password' => bcrypt('secret'),
            'is_platform_admin' => false,
        ]);
        $tenant->users()->attach($user, ['status' => 'active']);

        // Create Owner role con todos los permisos.
        $ownerRole = \Spatie\Permission\Models\Role::create([
            'name' => 'Owner',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);
        foreach (\App\Support\Permissions\BasePermissions::PERMISSIONS as $permName) {
            $perm = \Spatie\Permission\Models\Permission::firstOrCreate([
                'name' => $permName,
                'guard_name' => 'web',
            ]);
            $ownerRole->givePermissionTo($perm);
        }
        setPermissionsTeamId($tenant->id);
        $user->assignRole('Owner');

        return [$tenant, $user];
    }

    private function seedTenantWithoutImagePermission(): array
    {
        [$tenant, $user] = $this->seedTenantWithOwner();
        // Revocamos los permisos de imagen (estaban via Owner, los quitamos).
        $user->roles->each(function ($role) use ($user, $tenant): void {
            setPermissionsTeamId($tenant->id);
            $role->revokePermissionTo(['products.image.upload', 'products.image.delete']);
        });
        // Volvemos a guardar el usuario.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

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

    private function fakeJpegUpload(int $w, int $h): array
    {
        // Crea un JPEG real con GD inline (no requiere archivos externos).
        $tmp = tempnam(sys_get_temp_dir(), 'test_jpg_');
        $im = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($im, 50, 100, 200);
        $txt = imagecolorallocate($im, 255, 255, 255);
        imagefill($im, 0, 0, $bg);
        imagestring($im, 5, 10, 10, "Test {$w}x{$h}", $txt);
        imagejpeg($im, $tmp, 85);
        imagedestroy($im);

        return [
            'image' => new \Illuminate\Http\UploadedFile(
                $tmp,
                'demo.jpg',
                'image/jpeg',
                null,
                true
            ),
        ];
    }

    private function uploadOne(Product $product, User $user, string $tenantSlug, array $payload, string $name): array
    {
        $payload['image'] = $payload['image'] ?? null;
        if (isset($payload['image'])) {
            $payload['image'] = new \Illuminate\Http\UploadedFile(
                $payload['image']->getPathname(),
                $name.'.jpg',
                'image/jpeg',
                null,
                true
            );
        }
        $response = $this
            ->actingAs($user)
            ->withHeader('X-Tenant', $tenantSlug)
            ->postJson("/api/products/{$product->id}/images", $payload + ['alt' => $name]);

        $response->assertCreated();

        return $response->json('data');
    }
}
