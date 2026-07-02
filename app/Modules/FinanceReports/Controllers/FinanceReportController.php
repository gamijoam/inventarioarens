<?php

namespace App\Modules\FinanceReports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FinanceReports\Requests\FinanceReportRequest;
use App\Modules\FinanceReports\Services\FinanceReportService;
use Illuminate\Http\JsonResponse;

class FinanceReportController extends Controller
{
    public function summary(FinanceReportRequest $request, FinanceReportService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->summary($request->filters())]);
    }

    public function receivables(FinanceReportRequest $request, FinanceReportService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->receivables($request->filters())]);
    }

    public function payables(FinanceReportRequest $request, FinanceReportService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->payables($request->filters())]);
    }
}
