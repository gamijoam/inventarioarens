<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Requests\DashboardSummaryRequest;
use App\Modules\Dashboard\Services\DashboardSummaryService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function summary(DashboardSummaryRequest $request, DashboardSummaryService $dashboard): JsonResponse
    {
        return response()->json([
            'data' => $dashboard->summary($request->filters()),
        ]);
    }
}
