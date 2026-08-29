<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\FiscalVatReportRequest;
use App\Modules\Reports\Services\FiscalVatReportService;
use Illuminate\Http\JsonResponse;

class FiscalReportController extends Controller
{
    public function iva(FiscalVatReportRequest $request, FiscalVatReportService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->iva($request->filters())]);
    }
}
