<?php

namespace App\Modules\InventoryCenter\Controllers;

use App\Modules\InventoryCenter\Requests\InventoryCenterSummaryRequest;
use App\Modules\InventoryCenter\Services\InventoryCenterSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class InventoryCenterController extends Controller
{
    public function summary(InventoryCenterSummaryRequest $request, InventoryCenterSummaryService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->summary($request->validated()),
        ]);
    }
}
