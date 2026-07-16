<?php

namespace App\Modules\InventoryCenter\Controllers;

use App\Modules\InventoryCenter\Requests\InventoryCenterBulkActionRequest;
use App\Modules\InventoryCenter\Requests\InventoryCenterProductAuditsRequest;
use App\Modules\InventoryCenter\Requests\InventoryCenterProductMovementsRequest;
use App\Modules\InventoryCenter\Requests\InventoryCenterProductSerialsRequest;
use App\Modules\InventoryCenter\Requests\InventoryCenterSummaryRequest;
use App\Modules\InventoryCenter\Requests\ReorderSuggestionsRequest;
use App\Modules\InventoryCenter\Requests\RecalculateProductPriceRequest;
use App\Modules\InventoryCenter\Requests\UpdateProductProfitMarginRequest;
use App\Modules\InventoryCenter\Services\InventoryAlertService;
use App\Modules\InventoryCenter\Services\InventoryCenterBulkActionService;
use App\Modules\InventoryCenter\Services\InventoryCenterMovementService;
use App\Modules\InventoryCenter\Services\InventoryCenterProductDetailService;
use App\Modules\InventoryCenter\Services\InventoryCenterSummaryService;
use App\Modules\InventoryCenter\Services\RecalculatePriceService;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Resources\ProductResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class InventoryCenterController extends Controller
{
    public function summary(InventoryCenterSummaryRequest $request, InventoryCenterSummaryService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->summary($request->validated()),
        ]);
    }

    public function export(InventoryCenterSummaryRequest $request, InventoryCenterSummaryService $service): Response
    {
        $filename = 'inventario_'.now()->format('Ymd_His').'.csv';

        return response($service->exportCsv($request->validated()), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function bulkAction(InventoryCenterBulkActionRequest $request, InventoryCenterBulkActionService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->apply($request->validated(), $request->user()?->id),
        ]);
    }

    public function movements(InventoryCenterProductMovementsRequest $request, InventoryCenterMovementService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->page($request->validated()),
        ]);
    }

    public function product(Product $product, InventoryCenterProductDetailService $service): JsonResponse
    {
        Gate::authorize('view', $product);

        return response()->json([
            'data' => $service->detail($product),
        ]);
    }

    public function productSerials(
        InventoryCenterProductSerialsRequest $request,
        Product $product,
        InventoryCenterProductDetailService $service
    ): JsonResponse {
        Gate::authorize('view', $product);

        return response()->json([
            'data' => $service->serialsPage($product, $request->validated()),
        ]);
    }

    public function productMovements(
        InventoryCenterProductMovementsRequest $request,
        Product $product,
        InventoryCenterProductDetailService $service
    ): JsonResponse {
        Gate::authorize('view', $product);

        return response()->json([
            'data' => $service->movementsPage($product, $request->validated()),
        ]);
    }

    public function productAudits(
        InventoryCenterProductAuditsRequest $request,
        Product $product,
        InventoryCenterProductDetailService $service
    ): JsonResponse {
        Gate::authorize('view', $product);

        return response()->json([
            'data' => $service->auditsPage($product, $request->validated()),
        ]);
    }

    public function productStockByWarehouse(Product $product, InventoryCenterProductDetailService $service): JsonResponse
    {
        Gate::authorize('view', $product);

        return response()->json([
            'data' => $service->stockByWarehousePage($product),
        ]);
    }

    public function productStockStatus(Product $product, InventoryAlertService $alerts): JsonResponse
    {
        Gate::authorize('view', $product);

        return response()->json([
            'data' => $alerts->stockStatus($product),
        ]);
    }

    public function reorderSuggestions(ReorderSuggestionsRequest $request, InventoryAlertService $alerts): JsonResponse
    {
        return response()->json([
            'data' => $alerts->reorderSuggestions($request->validated()),
        ]);
    }

    public function alertsSummary(Request $request, InventoryAlertService $alerts): JsonResponse
    {
        $threshold = (float) $request->input('fallback_threshold', 3);

        return response()->json([
            'data' => $alerts->summary($threshold),
        ]);
    }

    /**
     * POST /api/inventory-center/products/{product}/recalculate-price
     *
     * Recalcula el base_price = average_cost * (1 + profit_margin / 100).
     * Acepta `profit_margin` opcional en el body para override y guardar.
     */
    public function recalculateProductPrice(
        RecalculateProductPriceRequest $request,
        Product $product,
        RecalculatePriceService $service
    ): JsonResponse {
        Gate::authorize('update', $product);

        $override = $request->filled('profit_margin') ? (float) $request->input('profit_margin') : null;
        $result = $service->recalculate($product, $override);

        return response()->json([
            'data' => [
                'product_id' => $product->id,
                'base_price' => $result['base_price'],
                'profit_margin' => $result['profit_margin'],
                'average_cost' => $result['wac'],
            ],
        ]);
    }

    /**
     * PATCH /api/inventory-center/products/{product}/profit-margin
     *
     * Actualiza el profit_margin y (si hay WAC) recalcula el base_price
     * automaticamente.
     */
    public function updateProductProfitMargin(
        UpdateProductProfitMarginRequest $request,
        Product $product,
        RecalculatePriceService $service
    ): JsonResponse {
        Gate::authorize('update', $product);

        $margin = (float) $request->input('profit_margin');
        $product->profit_margin = round($margin, 2);

        $newBasePrice = null;
        if ($product->average_cost !== null) {
            $newBasePrice = round(((float) $product->average_cost) * (1 + ($margin / 100)), 2);
            $product->base_price = $newBasePrice;
        }

        $product->save();

        return response()->json([
            'data' => [
                'product_id' => $product->id,
                'profit_margin' => (float) $product->profit_margin,
                'base_price' => $newBasePrice,
            ],
        ]);
    }
}
