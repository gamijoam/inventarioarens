<?php

namespace App\Modules\Sync\Services;

use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * SyncImageService — publicación de imágenes de producto entre nodos.
 *
 * Dirección local -> nube:
 *   El nodo local sube los bytes de la imagen a la nube (POST /api/sync/images)
 *   y luego emite `product.image.uploaded` con cloud_url apuntando a la base
 *   pública de la nube. Sin este paso, el evento llevaría http://localhost/...
 *   y la nube no podria descargar el archivo.
 *
 * Dirección nube -> local:
 *   La nube emite cloud_url con su APP_URL publico; el local descarga via
 *   SyncDownloadService (offline-first, sha256).
 */
class SyncImageService
{
    public function storeFromNode(Tenant $tenant, array $data, UploadedFile $file): array
    {
        $uuid = $data['uuid'];
        $productSku = $data['product_sku'];
        $variant = $data['variant'] ?? 'original';
        $sha256 = $data['sha256'];

        $product = Product::query()
            ->where('tenant_id', $tenant->id)
            ->where('sku', $productSku)
            ->first();

        if (! $product) {
            abort(422, 'El producto no existe en esta empresa.');
        }

        $bytes = $file->get();
        $actualHash = hash('sha256', $bytes);

        if (! hash_equals($actualHash, $sha256)) {
            abort(422, 'El hash sha256 de la imagen no coincide.');
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension());
        $now = now();
        $variantSuffix = $variant === 'original' ? '' : "_{$variant}";
        $relPath = "products/{$tenant->id}/{$now->format('Y')}/{$now->format('m')}/{$uuid}{$variantSuffix}.{$ext}";

        Storage::disk('product-images')->put($relPath, $bytes);

        $base = rtrim((string) config('app.url'), '/');

        return [
            'uuid' => $uuid,
            'variant' => $variant,
            'storage_path' => $relPath,
            'cloud_url' => "{$base}/storage/{$relPath}",
            'sha256' => $actualHash,
        ];
    }

    /**
     * Desde un nodo local: sube el binario a la nube. Devuelve la cloud_url
     * publica de la nube, o null si no hay nube configurada o falla.
     */
    public function publishToCloud(string $uuid, string $productSku, string $variant, string $sha256, string $filePath): ?array
    {
        $cloudUrl = rtrim((string) config('services.sync.cloud_url'), '/');
        $token = (string) config('services.sync.token');

        if ($cloudUrl === '' || $token === '') {
            return null;
        }

        if (! is_file($filePath)) {
            return null;
        }

        $response = Http::withToken($token)
            ->attach('image', file_get_contents($filePath), basename($filePath))
            ->post("{$cloudUrl}/sync/images", [
                'uuid' => $uuid,
                'product_sku' => $productSku,
                'variant' => $variant,
                'sha256' => $sha256,
            ]);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('data') ?? null;
    }
}
