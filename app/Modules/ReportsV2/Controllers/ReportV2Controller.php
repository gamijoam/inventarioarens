<?php

namespace App\Modules\ReportsV2\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ReportsV2\Requests\ReportV2Request;
use App\Modules\ReportsV2\Services\ReportQueryService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class ReportV2Controller extends Controller
{
    public function report(string $report, ReportV2Request $request, ReportQueryService $service): JsonResponse
    {
        try {
            return response()->json([
                'data' => $service->run($report, $request->filters()),
            ]);
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }
    }
}
