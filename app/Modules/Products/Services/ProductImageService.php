<?php

namespace App\Modules\Products\Services;

use App\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductImageVariant;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Servicio de imagenes de producto. Orquesta:
 *  1) Validacion + analisis del archivo (ImageProcessor).
 *  2) Generacion de las 3 variantes WebP/JPG en /tmp.
 *  3) Calculo de paths finales respetando multi-tenant (products/{tenant_id}/{yyyy}/{mm}/{uuid}.ext).
 *  4) Escritura a Storage::disk('product-images').
 *  5) Persistencia de ProductImage + 3 ProductImageVariant en una sola transaccion.
 *  6) Emision de outbox event 'product.image.uploaded' con cloud_url + variants + sha256.
 *
 * El delete hace la inversa (soft delete, luego un job limpia storage >30dias).
 */
class ProductImageService
{
    public function __construct(
        private readonly ImageProcessor $processor,
        private readonly TenantManager $tenants,
        private readonly SyncCatalogOutboxService $outbox,
    ) {}

    /**
     * Sube una imagen para un producto del tenant actual.
     * Si ya existe una imagen con el mismo sha256 en el mismo producto, devuelve
     * la existente (deduplicacion para que el user no llene el storage subiendo
     * el mismo archivo 5 veces).
     */
    public function upload(Product $product, UploadedFile $file, ?string $alt = null, ?User $uploadedBy = null): ProductImage
    {
        if (! $this->tenants->current()) {
            throw new RuntimeException('No hay tenant activo en el request.');
        }

        if ($uploadedBy === null) {
            $uploadedBy = auth()->user();
        }

        $tenantId = $this->tenants->current()->id;

        // 1) Analisis + validacion.
        $config = $this->processor->analyze($file);

        // 2) Deduplicacion: si ya existe imagen con mismo sha256 para este producto,
        //    devolver la existente en vez de duplicar.
        $existing = ProductImage::query()
            ->where('product_id', $product->id)
            ->where('sha256', $config['sha256'])
            ->first();
        if ($existing) {
            $this->outbox->imageUploaded($existing->fresh(['variants']));

            return $existing->fresh(['variants', 'product']);
        }

        // 3) Path layout: products/{tenant_id}/{yyyy}/{mm}/{uuid}.ext
        $uuid = (string) Str::uuid();
        $now = now();
        $relDir = "products/{$tenantId}/{$now->format('Y')}/{$now->format('m')}";
        $relPath = "{$relDir}/{$uuid}.{$this->processor->preferredGdFormat()['ext']}";

        // 4) Generar variantes en /tmp.
        $tmpBase = tempnam(sys_get_temp_dir(), 'prod_img_');
        if ($tmpBase === false) {
            throw new RuntimeException('No se pudo crear archivo temporal.');
        }
        $variantsWritten = $this->processor->generateVariants($file, $config, $tmpBase);

        // 5) Persistir archivos en storage + DB en una transaccion.
        $persistedImage = null;
        try {
            $persistedImage = DB::transaction(function () use ($product, $config, $variantsWritten, $relDir, $relPath, $alt, $uploadedBy, $file) {
                $disk = Storage::disk('product-images');
                $disk->put($relPath, file_get_contents($variantsWritten['original']['path']));

                // Crear ProductImage.
                $image = ProductImage::create([
                    'uuid' => Str::uuid()->toString(),
                    'product_id' => $product->id,
                    'uploaded_by' => $uploadedBy?->id,
                    'storage_path' => $relPath,
                    'mime' => $config['source']['mime'],
                    'size' => $config['source']['size'],
                    'original_name' => $file->getClientOriginalName(),
                    'width' => $config['source']['width'],
                    'height' => $config['source']['height'],
                    'sha256' => $config['sha256'],
                    'alt' => $alt,
                    'sort' => $this->nextSortFor($product->id),
                    'is_primary' => $this->isFirstImage($product->id),
                ]);

                // Crear variantes.
                $shortId = $this->uuidFrom($image->uuid);
                $variantPaths = [
                    'original' => $relPath,
                    'medium' => "{$relDir}/{$shortId}_medium.".$this->processor->preferredGdFormat()['ext'],
                    'thumb' => "{$relDir}/{$shortId}_thumb.".$this->processor->preferredGdFormat()['ext'],
                ];
                foreach ($variantsWritten as $key => $data) {
                    $variantPath = $variantPaths[$key];
                    $disk->put($variantPath, file_get_contents($data['path']));

                    ProductImageVariant::create([
                        'product_image_id' => $image->id,
                        'variant' => $key,
                        'storage_path' => $variantPath,
                        'mime' => $data['mime'],
                        'size' => $data['size'],
                        'width' => $data['width'],
                        'height' => $data['height'],
                    ]);
                }

                return $image;
            });
        } finally {
            foreach ($variantsWritten as $data) {
                @unlink($data['path']);
            }
            @unlink($tmpBase);
        }

        // Emitir evento outbox DESPUES del commit.
        $this->outbox->imageUploaded($persistedImage->fresh(['variants']));

        return $persistedImage->fresh(['variants', 'product']);
    }

    /**
     * Soft delete: marca deleted_at + emite evento outbox.
     * El job de limpieza (Nivel 3) borrara el archivo fisico despues de 30 dias.
     */
    public function delete(ProductImage $image): void
    {
        DB::transaction(function () use ($image) {
            $image->delete();
        });
        $this->outbox->imageDeleted($image);
    }

    public function setPrimary(ProductImage $image): void
    {
        DB::transaction(function () use ($image) {
            // Quitar primary de las demas imagenes del mismo producto.
            ProductImage::query()
                ->where('product_id', $image->product_id)
                ->where('id', '!=', $image->id)
                ->update(['is_primary' => false]);

            $image->update(['is_primary' => true]);
        });
        $this->outbox->imageUpdated($image->fresh(['variants']));
    }

    public function reorder(Product $product, array $orderedIds): void
    {
        DB::transaction(function () use ($product, $orderedIds) {
            foreach ($orderedIds as $sort => $id) {
                ProductImage::query()
                    ->where('product_id', $product->id)
                    ->where('id', $id)
                    ->update(['sort' => $sort]);
            }
        });
        // Re-emit updated por cada imagen (podria ser N+1; aceptable para
        // galerias pequenas).
        foreach ($orderedIds as $id) {
            $img = ProductImage::find($id);
            if ($img) {
                $this->outbox->imageUpdated($img->fresh(['variants']));
            }
        }
    }

    private function nextSortFor(int $productId): int
    {
        return (int) (ProductImage::query()->where('product_id', $productId)->max('sort') ?? -1) + 1;
    }

    private function isFirstImage(int $productId): bool
    {
        return ProductImage::query()->where('product_id', $productId)->where('is_primary', true)->doesntExist();
    }

    private function uuidFrom(string $uuid): string
    {
        // Extrae la primera "parte" del UUID v4 para nombres de archivo.
        return explode('-', $uuid)[0] ?? substr($uuid, 0, 8);
    }
}
