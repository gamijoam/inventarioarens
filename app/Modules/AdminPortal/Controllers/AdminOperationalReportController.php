<?php

namespace App\Modules\AdminPortal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AdminPortal\Requests\AdminOperationalReportRequest;
use App\Modules\AdminPortal\Services\AdminOperationalReportService;
use Illuminate\Http\JsonResponse;

class AdminOperationalReportController extends Controller
{
    public function show(AdminOperationalReportRequest $request, AdminOperationalReportService $reports): JsonResponse
    {
        return response()->json([
            'data' => $reports->summary($request->filters()),
        ]);
    }
}
