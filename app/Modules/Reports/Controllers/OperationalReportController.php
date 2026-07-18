<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\OperationalReportRequest;
use App\Modules\Reports\Services\OperationalReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OperationalReportController extends Controller
{
    public function catalog(OperationalReportRequest $request, OperationalReportService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->catalog($request->user())]);
    }

    public function dailyOperations(OperationalReportRequest $request, OperationalReportService $reports): JsonResponse
    {
        abort_unless($request->user()->can('reports.view') || $request->user()->can('reports.cash.view') || $request->user()->can('reports.sales.view'), Response::HTTP_FORBIDDEN);

        return response()->json(['data' => $reports->dailyOperations($request->filters())]);
    }

    public function salesDetail(OperationalReportRequest $request, OperationalReportService $reports): JsonResponse
    {
        abort_unless($request->user()->can('reports.view') || $request->user()->can('reports.sales.view'), Response::HTTP_FORBIDDEN);

        return response()->json(['data' => $reports->salesDetail($request->filters())]);
    }

    public function cashSessions(OperationalReportRequest $request, OperationalReportService $reports): JsonResponse
    {
        abort_unless($request->user()->can('reports.view') || $request->user()->can('reports.cash.view'), Response::HTTP_FORBIDDEN);

        return response()->json(['data' => $reports->cashSessions($request->filters())]);
    }

    public function paymentMethods(OperationalReportRequest $request, OperationalReportService $reports): JsonResponse
    {
        abort_unless($request->user()->can('reports.view') || $request->user()->can('reports.cash.view') || $request->user()->can('reports.sales.view'), Response::HTTP_FORBIDDEN);

        return response()->json(['data' => $reports->paymentMethods($request->filters())]);
    }
}
