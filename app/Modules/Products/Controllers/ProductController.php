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
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);

        return ProductResource::collection(
            Product::query()
                ->with(['saleExchangeRateType', 'warrantyPolicy'])
                ->orderBy('name')
                ->paginate(25)
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

        return ProductResource::make($product->load(['saleExchangeRateType', 'warrantyPolicy']));
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

        return ProductResource::make($product->refresh()->load(['saleExchangeRateType', 'warrantyPolicy']));
    }

    public function destroy(Product $product): Response
    {
        Gate::authorize('delete', $product);

        $product->update(['is_active' => false]);

        return response()->noContent();
    }
}
