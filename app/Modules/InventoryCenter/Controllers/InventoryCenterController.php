<?php

namespace App\Modules\InventoryCenter\Controllers;

use App\Modules\InventoryCenter\Requests\InventoryCenterProductAuditsRequest;
use App\Modules\InventoryCenter\Requests\InventoryCenterBulkActionRequest;
use App\Modules\InventoryCenter\Requests\InventoryCenterProductMovementsRequest;
use App\Modules\InventoryCenter\Requests\InventoryCenterProductSerialsRequest;
use App\Modules\InventoryCenter\Requests\InventoryCenterSummaryRequest;
use App\Modules\InventoryCenter\Services\InventoryCenterBulkActionService;
use App\Modules\InventoryCenter\Services\InventoryCenterProductDetailService;
use App\Modules\InventoryCenter\Services\InventoryCenterSummaryService;
use App\Modules\Products\Models\Product;
use Illuminate\Http\JsonResponse;
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
}
