<?php

namespace App\Modules\AdminPortal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AdminPortal\Requests\AdminPosSalesRequest;
use App\Modules\AdminPortal\Services\AdminPosSalesService;
use App\Modules\POS\Models\PosOrder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminPosSalesController extends Controller
{
    public function index(AdminPosSalesRequest $request, AdminPosSalesService $sales): JsonResponse|StreamedResponse
    {
        if ($request->wantsCsvExport()) {
            $export = $sales->export($request->filters());

            return response()->streamDownload(function () use ($export): void {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, $export['headers']);

                foreach ($export['rows'] as $row) {
                    fputcsv($handle, $row);
                }

                fclose($handle);
            }, $export['filename'], [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return response()->json([
            'data' => $sales->index($request->filters()),
        ]);
    }

    public function show(AdminPosSalesRequest $request, AdminPosSalesService $sales, PosOrder $posOrder): JsonResponse
    {
        return response()->json([
            'data' => $sales->detail($posOrder),
        ]);
    }
}
