<?php

namespace App\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Requests\StoreProductVariantRequest;
use App\Modules\Products\Requests\UpdateProductVariantRequest;
use App\Modules\Products\Resources\ProductVariantResource;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProductVariantController extends Controller
{
    public function index(Product $product): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ProductVariant::class, $product]);

        $variants = $product->variants()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return ProductVariantResource::collection($variants);
    }

    public function store(StoreProductVariantRequest $request, Product $product): JsonResponse
    {
        Gate::authorize('create', [ProductVariant::class, $product]);

        $variant = DB::transaction(function () use ($product, $request) {
            $position = $request->input('position', $product->variants()->count());

            return $product->variants()->create([
                'color' => $request->input('color'),
                'color_hex' => $request->input('color_hex'),
                'sku_variant' => $request->input('sku_variant'),
                'barcode_variant' => $request->input('barcode_variant'),
                'price_override' => $request->input('price_override'),
                'is_active' => $request->boolean('is_active', true),
                'position' => $position,
            ]);
        });

        // Emitir evento de sync para que la variante (presentacion/color)
        // se replique a la nube. Sin esto, las compras/POS que usan la
        // variante por sku_variant no pueden resolverla en el otro nodo.
        app(SyncCatalogOutboxService::class)->variantCreated($variant);

        return ProductVariantResource::make($variant)->response()->setStatusCode(201);
    }

    public function update(UpdateProductVariantRequest $request, Product $product, ProductVariant $variant): ProductVariantResource
    {
        Gate::authorize('update', [ProductVariant::class, $product]);

        if ((int) $variant->product_id !== (int) $product->id) {
            abort(404);
        }

        $variant->update($request->validated());
        $variant->refresh();

        app(SyncCatalogOutboxService::class)->variantUpdated($variant);

        return ProductVariantResource::make($variant);
    }

    public function destroy(Product $product, ProductVariant $variant): JsonResponse
    {
        Gate::authorize('delete', [ProductVariant::class, $product]);

        if ((int) $variant->product_id !== (int) $product->id) {
            abort(404);
        }

        $total = (float) StockBalance::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $variant->tenant_id)
            ->where('product_variant_id', $variant->id)
            ->sum('quantity_available');
        if ($total > 0) {
            throw ValidationException::withMessages([
                'variant' => 'No se puede eliminar una variante con stock disponible.',
            ]);
        }
        $movementExists = StockMovement::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $variant->tenant_id)
            ->where('product_variant_id', $variant->id)
            ->exists();
        if ($movementExists) {
            throw ValidationException::withMessages([
                'variant' => 'No se puede eliminar una variante con movimientos historicos.',
            ]);
        }

        ProductUnit::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $variant->tenant_id)
            ->where('product_variant_id', $variant->id)
            ->update(['product_variant_id' => null]);
        $variant->delete();

        app(SyncCatalogOutboxService::class)->variantDeleted($variant);

        return response()->json(['deleted' => true]);
    }
}
