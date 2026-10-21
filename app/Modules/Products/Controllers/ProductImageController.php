<?php

namespace App\Modules\Products\Controllers;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Requests\UpdateProductImageRequest;
use App\Modules\Products\Requests\UploadProductImageRequest;
use App\Modules\Products\Resources\ProductImageResource;
use App\Modules\Products\Services\ProductImageService;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Support\PublicHttpUrlGuard;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProductImageController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly ProductImageService $service) {}

    public function index(Request $request, Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        $images = $product->images()
            ->with('variants')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => ProductImageResource::collection($images)->resolve(),
        ]);
    }

    public function store(UploadProductImageRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        try {
            $image = $this->service->upload(
                product: $product,
                file: $request->file('image'),
                alt: $request->input('alt'),
                uploadedBy: $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [
                    'image' => [$e->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'data' => (new ProductImageResource($image->fresh(['variants'])))->resolve(),
        ], 201);
    }

    public function storeFromUrl(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'url' => ['required', 'url', 'max:500'],
            'alt' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $image = $this->service->fromUrl(
                product: $product,
                url: $data['url'],
                alt: $data['alt'] ?? null,
                uploadedBy: $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [
                    'url' => [$e->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'data' => (new ProductImageResource($image->fresh(['variants'])))->resolve(),
        ], 201);
    }

    public function update(UpdateProductImageRequest $request, Product $product, ProductImage $image): JsonResponse
    {
        $this->authorize('update', $product);
        if ($image->product_id !== $product->id) {
            return response()->json(['message' => 'La imagen no pertenece al producto.'], 404);
        }

        $data = $request->validated();
        if (array_key_exists('alt', $data)) {
            $image->alt = $data['alt'];
        }
        if (array_key_exists('sort', $data)) {
            $image->sort = (int) $data['sort'];
        }
        if (array_key_exists('is_primary', $data) && $data['is_primary']) {
            $this->service->setPrimary($image);

            return response()->json([
                'data' => (new ProductImageResource($image->fresh(['variants'])))->resolve(),
            ]);
        }
        $image->save();

        // Sync event para propagar alt/sort.
        app(SyncCatalogOutboxService::class)->imageUpdated($image->fresh(['variants']));

        return response()->json([
            'data' => (new ProductImageResource($image->fresh(['variants'])))->resolve(),
        ]);
    }

    public function destroy(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        $this->authorize('update', $product);
        if ($image->product_id !== $product->id) {
            return response()->json(['message' => 'La imagen no pertenece al producto.'], 404);
        }

        $this->service->delete($image);

        return response()->json(['message' => 'Imagen eliminada.']);
    }

    public function reorder(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['integer', 'exists:product_images,id'],
        ]);

        $this->service->reorder($product, $data['ordered_ids']);

        return response()->json(['message' => 'Orden actualizado.']);
    }

    public function resolveImageUrl(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:1000'],
        ]);

        $url = trim($data['url']);
        $user = $request->user();
        $tenant = app(TenantManager::class)->current();

        abort_unless(
            $user
            && $tenant
            && $user->belongsToTenant($tenant)
            && ($user->hasPermissionTo('products.create') || $user->hasPermissionTo('products.update')),
            403,
        );

        try {
            PublicHttpUrlGuard::assertPublic($url);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // 1. Si termina directamente en extensión de imagen, asumimos que es imagen directa
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'avif'])) {
            return response()->json([
                'image_url' => $url,
                'source' => 'direct',
            ]);
        }

        // 2. Si no es imagen directa, descargamos el contenido para ver si es HTML
        try {
            $response = PublicHttpUrlGuard::get($url, [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/*,*/*;q=0.8',
            ], 8);

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'No se pudo acceder al enlace proporcionado (código HTTP '.$response->status().').',
                ], 422);
            }

            $contentType = strtolower($response->header('Content-Type') ?? '');

            if (str_starts_with($contentType, 'image/')) {
                return response()->json([
                    'image_url' => $url,
                    'source' => 'direct_content_type',
                ]);
            }

            $contentLength = (int) $response->header('Content-Length', 0);
            if ($contentLength > 5 * 1024 * 1024) {
                return response()->json(['message' => 'La página supera el tamaño máximo permitido.'], 422);
            }

            $html = $response->body();

            // Buscar og:image
            if (preg_match('/<meta[^>]+property=[\'"](?:og:image|og:image:url)[\'"][^>]+content=[\'"]([^\'"]+)[\'"]/i', $html, $matches) ||
                preg_match('/<meta[^>]+content=[\'"]([^\'"]+)[\'"][^>]+property=[\'"](?:og:image|og:image:url)[\'"]/i', $html, $matches)) {
                $extracted = html_entity_decode(trim($matches[1]));
                return response()->json([
                    'image_url' => $extracted,
                    'source' => 'og_image',
                ]);
            }

            // Buscar twitter:image
            if (preg_match('/<meta[^>]+(?:name|property)=[\'"]twitter:image[\'"][^>]+content=[\'"]([^\'"]+)[\'"]/i', $html, $matches) ||
                preg_match('/<meta[^>]+content=[\'"]([^\'"]+)[\'"][^>]+(?:name|property)=[\'"]twitter:image[\'"]/i', $html, $matches)) {
                $extracted = html_entity_decode(trim($matches[1]));
                return response()->json([
                    'image_url' => $extracted,
                    'source' => 'twitter_image',
                ]);
            }

            return response()->json([
                'message' => 'El enlace ingresado es una página web pero no contiene una imagen principal identificable (og:image). Por favor copia el enlace directo de la imagen haciendo clic derecho sobre la foto > "Copiar dirección de imagen".',
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al analizar el enlace: '.$e->getMessage(),
            ], 422);
        }
    }
}
