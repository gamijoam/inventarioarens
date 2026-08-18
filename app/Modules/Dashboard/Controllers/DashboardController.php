<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Requests\DashboardSummaryRequest;
use App\Modules\Dashboard\Services\DashboardSummaryService;
use App\Modules\Dashboard\Services\OrganizationDashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function summary(
        DashboardSummaryRequest $request,
        DashboardSummaryService $dashboard,
        OrganizationDashboardService $organization,
    ): JsonResponse {
        if ($request->scope() === 'organization') {
            $group = $request->resolveGroup();

            return response()->json([
                'data' => $organization->summary($request->filters(), $group),
            ]);
        }

        return response()->json([
            'data' => $dashboard->summary($request->filters()),
        ]);
    }
}
