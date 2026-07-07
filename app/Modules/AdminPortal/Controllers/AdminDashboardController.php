<?php

namespace App\Modules\AdminPortal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AdminPortal\Requests\AdminDashboardRequest;
use App\Modules\AdminPortal\Services\AdminDashboardService;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function show(AdminDashboardRequest $request, AdminDashboardService $dashboard): JsonResponse
    {
        return response()->json([
            'data' => $dashboard->summary($request->filters()),
        ]);
    }
}
