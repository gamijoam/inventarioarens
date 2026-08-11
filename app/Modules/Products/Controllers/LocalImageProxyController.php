<?php

namespace App\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Products\Models\ProductImage;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * LocalImageProxyController - Sirve imagenes de productos con cache local.
 *
 * Caso de uso (Fase 3 - multi-imagen offline-first):
 *  - El frontend muestra imagenes via <img src="/api/images/{uuid}?variant=thumb">.
 *  - En el VPS cloud, /storage/products/... se sirve directo por nginx (symlink + alias).
 *  - En un nodo local, las imagenes se descargan via sync a storage/app/synced-images/.
 *  - Este controller unifica ambos: si el archivo esta en synced-images lo sirve
 *    localmente; si no, hace 302 a la cloud_url original para que el browser
 *    lo descargue del cloud.
 *
 * Variante: se pasa via query `?variant=thumb|medium|original`. El proxy
 * resuelve el storage_path de esa variante (o del original si no existe) y
 * sirve desde synced-images, o 302 a la cloud_url de esa variante.
 *
 * Razon de ser public (sin auth):
 *  - Los <img> del navegador no envian headers custom (X-Requested-With, etc)
 *    ni Authorization Bearer. Solo envian cookies same-origin.
 *  - El CSRF check del AuthenticateApiToken rechazaria los <img> por faltar
 *    X-Requested-With. Por eso este endpoint es PUBLIC y se auto-identifica
 *    por el UUID (v4, random, no enumerable).
 *  - Un atacante que conozca un UUID especifico podria ver la imagen, pero
 *    no podria listar todas (128 bits de entropia). Acceptable.
 *
 * Uso:
 *  - GET /api/images/{uuid}          -> sirve bytes del synced-images, o 302 al cloud.
 *  - GET /api/images/{uuid}?variant=thumb
 *  - GET /api/images/{uuid}?v=<hash>  -> cache-busting via query string (no usado internamente,
 *                                          pero el frontend puede agregarlo cuando el archivo cambia).
 */
class LocalImageProxyController extends Controller
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function show(Request $request, string $uuid): Response
    {
        // Validar formato UUID v4 (defensa en profundidad contra path traversal).
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            abort(404);
        }

        $image = ProductImage::query()
            ->where('uuid', $uuid)
            // Excluir soft-deleted: una imagen borrada no debe servirse desde
            // el cache local NI via 302 al cloud. La UI debe dejar de mostrarla
            // inmediatamente. Si el frontend cacheo la URL, ve una imagen rota,
            // que es exactamente lo que queremos (se limpio en la nube).
            ->with('variants')
            ->first();

        if (! $image) {
            abort(404);
        }

        $variant = $request->query('variant', 'original');
        $storagePath = $this->resolveVariantPath($image, $variant);

        // Prioridad 1: archivo local en synced-images (caso local node).
        $localPath = $this->resolveLocalPath($storagePath);
        if ($localPath !== null && is_file($localPath)) {
            return $this->serveFile($localPath, $image->mime);
        }

        // Prioridad 1b: archivo local en el storage de subidas (nodo que subio
        // la imagen y aun no la bajo via sync). root = storage/app/public.
        $uploadedPath = $this->resolveUploadedPath($storagePath);
        if ($uploadedPath !== null && is_file($uploadedPath)) {
            return $this->serveFile($uploadedPath, $image->mime);
        }

        // Prioridad 2: 302 a la cloud_url original (caso primera visita antes
        // de que el sync worker descargue, o si la imagen vive solo en el cloud).
        $cloudUrl = $this->resolveCloudUrl($image, $variant, $storagePath);
        if ($cloudUrl !== null) {
            return new RedirectResponse($cloudUrl, 302, [
                'Cache-Control' => 'public, max-age=300',  // 5 min: el sync worker bajara pronto
            ]);
        }

        // No hay archivo local ni URL remota: 404.
        abort(404);
    }

    /**
     * Resuelve el storage_path de la variante pedida (o del original si la
     * variante no existe, por ejemplo imagenes legacy).
     */
    private function resolveVariantPath(ProductImage $image, string $variant): string
    {
        if ($variant === 'original') {
            return (string) $image->storage_path;
        }

        $variantRow = $image->variants->firstWhere('variant', $variant);
        if ($variantRow && $variantRow->storage_path) {
            return (string) $variantRow->storage_path;
        }

        return (string) $image->storage_path;
    }

    /**
     * Resuelve la ruta absoluta al archivo en synced-images segun el
     * storage_path (o cloud_storage_path) de la imagen. Devuelve null si la
     * imagen es solo una URL externa (no un archivo local en el cloud).
     */
    private function resolveLocalPath(string $storagePath): ?string
    {
        $disk = Storage::disk('synced-images');
        if (! $storagePath || str_starts_with($storagePath, 'http')) {
            return null;
        }

        return $disk->path($storagePath);
    }

    /**
     * Resuelve la ruta absoluta en el storage de subidas locales
     * (storage/app/public, disk product-images). Cubre el caso de una imagen
     * subida directamente en este nodo local.
     */
    private function resolveUploadedPath(string $storagePath): ?string
    {
        $disk = Storage::disk('product-images');
        if (! $storagePath || str_starts_with($storagePath, 'http')) {
            return null;
        }

        return $disk->path($storagePath);
    }

    /**
     * Resuelve la cloud_url completa de la variante pedida. Si storage_path ya
     * es una URL absoluta (caso comun: sync incompleto, la fila viene con la
     * URL del cloud), la usamos directo. Si es un path relativo (caso comun en
     * el cloud: ya esta subido a /storage/products/...), construimos la URL.
     */
    private function resolveCloudUrl(ProductImage $image, string $variant, string $storagePath): ?string
    {
        $path = $storagePath;
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Path relativo: construir URL absoluta con APP_URL.
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * Sirve un archivo del disco con content-type y cache-control correctos.
     */
    private function serveFile(string $absolutePath, string $mime): BinaryFileResponse
    {
        $response = new BinaryFileResponse($absolutePath);
        $response->headers->set('Content-Type', $mime);
        // Cache largo: la URL no cambia (es por UUID), asi que podemos cachear 30 dias.
        $response->headers->set('Cache-Control', 'public, max-age=2592000, immutable');

        return $response;
    }
}
