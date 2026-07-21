<?php

namespace App\Modules\Products\Controllers;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Requests\UpdateProductImageRequest;
use App\Modules\Products\Requests\UploadProductImageRequest;
use App\Modules\Products\Resources\ProductImageResource;
use App\Modules\Products\Services\ProductImageService;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProductImageController extends Controller
{
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

        $image = $this->service->upload(
            product: $product,
            file: $request->file('image'),
            alt: $request->input('alt'),
            uploadedBy: $request->user(),
        );

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
        app(\App\Modules\Sync\Services\SyncCatalogOutboxService::class)->imageUpdated($image->fresh(['variants']));

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
}
