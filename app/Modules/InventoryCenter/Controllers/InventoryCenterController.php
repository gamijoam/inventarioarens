<?php

namespace App\Modules\InventoryCenter\Controllers;

use App\Modules\InventoryCenter\Requests\InventoryCenterSummaryRequest;
use App\Modules\InventoryCenter\Services\InventoryCenterProductDetailService;
use App\Modules\InventoryCenter\Services\InventoryCenterSummaryService;
use App\Modules\Products\Models\Product;
use Illuminate\Http\JsonResponse;
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

    public function product(Product $product, InventoryCenterProductDetailService $service): JsonResponse
    {
        Gate::authorize('view', $product);

        return response()->json([
            'data' => $service->detail($product),
        ]);
    }
}
