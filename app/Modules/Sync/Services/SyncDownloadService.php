<?php

namespace App\Modules\Sync\Services;

use App\Modules\Products\Models\ProductImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * SyncDownloadService - Descarga archivos binarios del cloud al local.
 *
 * Caso de uso (Fase 3 - imagenes offline-first):
 *  - El VPS cloud tiene las imagenes originales en storage/app/public/products/.
 *  - Los nodos locales las necesitan localmente para servirlas sin depender
 *    de internet (proxy LocalImageProxyController).
 *  - Cuando llega un evento product.image.uploaded via sync_inbox, este
 *    servicio baja el archivo al synced-images local.
 *
 * Idempotencia:
 *  - Antes de descargar, verifica si el archivo ya existe y si su sha256
 *    coincide. Si coincide, skip. Si difiere, re-descarga (caso: el user
 *    subio una nueva imagen con el mismo UUID - imposible en la practica
 *    porque el UUID se genera nuevo, pero defensivo).
 *
 * Confiabilidad:
 *  - Reintenta 3 veces con backoff exponencial.
 *  - Si el download falla, loguea warning y sigue (el proxy hara 302 al cloud).
 *  - Timeout de 30s por intento.
 *
 * Cache:
 *  - El synced-images es un filesystem local. NO tiene LRU automatico.
 *  - Un job de limpieza (Nivel 4) borrara archivos con deleted_at > 30 dias.
 *  - Para LRU, agregar disk metadata de last_accessed.
 */
class SyncDownloadService
{
    private const MAX_RETRIES = 3;

    private const TIMEOUT_SECONDS = 30;

    /**
     * Descarga una imagen a synced-images. Idempotente.
     *
     * @return bool true si descargo (o ya estaba al dia), false si fallo
     *              despues de todos los reintentos.
     */
    public function downloadImage(ProductImage $image): bool
    {
        $disk = Storage::disk('synced-images');

        // Determinar source URL y target path.
        $sourceUrl = $this->resolveSourceUrl($image);
        $targetPath = $image->storage_path;
        if (! $sourceUrl || ! $targetPath || str_starts_with($targetPath, 'http')) {
            // storage_path es URL absoluta o vacia: nada que descargar localmente.
            return true;
        }

        // Idempotencia: si el archivo ya existe y el sha256 coincide, skip.
        if ($disk->exists($targetPath)) {
            $existingHash = @hash_file('sha256', $disk->path($targetPath));
            if ($existingHash === $image->sha256) {
                return true;
            }
        }

        // Descargar con reintentos.
        $bytes = $this->downloadWithRetries($sourceUrl);
        if ($bytes === null) {
            Log::warning('sync.image.download_failed', [
                'image_uuid' => $image->uuid,
                'source' => $sourceUrl,
                'attempts' => self::MAX_RETRIES,
            ]);

            return false;
        }

        // Verificar integridad (opcional, pero barato).
        $downloadedHash = hash('sha256', $bytes);
        if ($image->sha256 && $downloadedHash !== $image->sha256) {
            Log::warning('sync.image.hash_mismatch', [
                'image_uuid' => $image->uuid,
                'expected' => $image->sha256,
                'actual' => $downloadedHash,
            ]);
            // Igual guardamos: el 302 fallback al cloud funcionara mientras tanto.
        }

        $disk->put($targetPath, $bytes);

        // Tambien descargar las 3 variantes si estan definidas (medium, thumb).
        // El frontend usa thumb/medium para UI; tenerlas locales mejora la UX offline.
        // Si no se pueden, no es bloqueante: el proxy hara 302 variante por variante.
        $this->downloadVariants($image);

        return true;
    }

    /**
     * Resuelve la URL source: cloud_url, o construye desde APP_URL si el
     * storage_path es relativo.
     */
    private function resolveSourceUrl(ProductImage $image): ?string
    {
        $path = $image->storage_path;
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * Descarga con reintentos + backoff exponencial (1s, 2s, 4s).
     */
    private function downloadWithRetries(string $url): ?string
    {
        $delay = 1;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders(['User-Agent' => 'INVENTARIOARENS-Sync/1.0'])
                    ->get($url);

                if ($response->successful()) {
                    return $response->body();
                }

                Log::debug('sync.image.attempt_failed', [
                    'url' => $url,
                    'attempt' => $attempt,
                    'status' => $response->status(),
                ]);
            } catch (\Throwable $e) {
                Log::debug('sync.image.attempt_exception', [
                    'url' => $url,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < self::MAX_RETRIES) {
                sleep($delay);
                $delay *= 2;
            }
        }

        return null;
    }

    /**
     * Descarga las 3 variantes (original, medium, thumb). Best-effort:
     * si alguna falla, el proxy hara 302 a esa variante en el cloud.
     */
    private function downloadVariants(ProductImage $image): void
    {
        $variants = $image->variants()->get();
        foreach ($variants as $variant) {
            $url = $this->resolveSourceUrlForVariant($variant);
            if (! $url || str_starts_with($variant->storage_path, 'http')) {
                continue;
            }
            $disk = Storage::disk('synced-images');
            if ($disk->exists($variant->storage_path)) {
                continue; // ya esta
            }
            $bytes = $this->downloadWithRetries($url);
            if ($bytes !== null) {
                $disk->put($variant->storage_path, $bytes);
            }
        }
    }

    private function resolveSourceUrlForVariant($variant): ?string
    {
        $path = $variant->storage_path;
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
