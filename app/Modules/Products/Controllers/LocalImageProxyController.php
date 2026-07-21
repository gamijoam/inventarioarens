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
 *  - El frontend muestra imagenes via <img src="/api/images/{uuid}">.
 *  - En el VPS cloud, /storage/products/... se sirve directo por nginx (symlink + alias).
 *  - En un nodo local, las imagenes se descargan via sync a storage/app/synced-images/.
 *  - Este controller unifica ambos: si el archivo esta en synced-images lo sirve
 *    localmente; si no, hace 302 a la cloud_url original para que el browser
 *    lo descargue del cloud.
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
            ->first();

        if (! $image) {
            abort(404);
        }

        // Prioridad 1: archivo local en synced-images (caso local node).
        $localPath = $this->resolveLocalPath($image);
        if ($localPath !== null && is_file($localPath)) {
            return $this->serveFile($localPath, $image->mime);
        }

        // Prioridad 2: 302 a la cloud_url original (caso primera visita antes
        // de que el sync worker descargue, o si la imagen vive solo en el cloud).
        $cloudUrl = $this->resolveCloudUrl($image);
        if ($cloudUrl !== null) {
            return new RedirectResponse($cloudUrl, 302, [
                'Cache-Control' => 'public, max-age=300',  // 5 min: el sync worker bajara pronto
            ]);
        }

        // No hay archivo local ni URL remota: 404.
        abort(404);
    }

    /**
     * Resuelve la ruta absoluta al archivo en synced-images/products/{tenant_id}/...
     * segun el storage_path de la imagen. Devuelve null si la imagen es solo
     * una URL externa (no un archivo local en el cloud).
     */
    private function resolveLocalPath(ProductImage $image): ?string
    {
        $disk = Storage::disk('synced-images');
        $relative = $image->storage_path;
        if (! $relative || str_starts_with($relative, 'http')) {
            return null;
        }

        return $disk->path($relative);
    }

    /**
     * Resuelve la cloud_url completa. Si storage_path ya es una URL absoluta
     * (caso comun: sync incompleto, la fila viene con la URL del cloud), la
     * usamos directo. Si es un path relativo (caso comun en el cloud: ya esta
     * subido a /storage/products/...), construimos la URL absoluta.
     */
    private function resolveCloudUrl(ProductImage $image): ?string
    {
        $path = $image->storage_path;
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
