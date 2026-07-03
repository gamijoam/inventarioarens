<?php

namespace App\Modules\Products\Controllers;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Requests\StoreProductRequest;
use App\Modules\Products\Requests\UpdateProductRequest;
use App\Modules\Products\Resources\ProductPriceResource;
use App\Modules\Products\Resources\ProductResource;
use App\Modules\Products\Services\ProductPriceService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);

        $search = trim((string) $request->query('search', ''));
        $normalizedSearch = mb_strtolower($search);
        $limit = min(max((int) $request->query('limit', 25), 1), 100);

        return ProductResource::collection(
            Product::query()
                ->with(['saleExchangeRateType', 'warrantyPolicy'])
                ->when($search !== '', function ($query) use ($normalizedSearch): void {
                    $query->where(function ($query) use ($normalizedSearch): void {
                        $query
                            ->whereRaw('LOWER(name) LIKE ?', ["%{$normalizedSearch}%"])
                            ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$normalizedSearch}%"]);
                    });
                })
                ->orderBy('name')
                ->paginate($limit)
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        Gate::authorize('create', Product::class);

        $product = Product::create($request->validated())
            ->refresh()
            ->load(['saleExchangeRateType', 'warrantyPolicy']);

        return ProductResource::make($product)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Product $product): ProductResource
    {
        Gate::authorize('view', $product);

        return ProductResource::make($product->load(['saleExchangeRateType', 'warrantyPolicy'])->loadCount('units'));
    }

    public function price(Product $product, ProductPriceService $priceService): ProductPriceResource
    {
        Gate::authorize('view', $product);

        return ProductPriceResource::make($priceService->quote($product));
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        Gate::authorize('update', $product);

        $data = $request->validated();

        if (
            array_key_exists('tracking_type', $data)
            && $data['tracking_type'] !== $product->tracking_type
            && $product->units()->exists()
        ) {
            throw ValidationException::withMessages([
                'tracking_type' => 'No se puede cambiar el tipo de control de un producto que ya tiene unidades serializadas.',
            ]);
        }

        $product->update($data);

        return ProductResource::make($product->refresh()->load(['saleExchangeRateType', 'warrantyPolicy'])->loadCount('units'));
    }

    public function destroy(Product $product): Response
    {
        Gate::authorize('delete', $product);

        $product->update(['is_active' => false]);

        return response()->noContent();
    }
}
