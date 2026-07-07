<?php

namespace App\Modules\AdminPortal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AdminPortal\Requests\AdminOperationalReportRequest;
use App\Modules\AdminPortal\Services\AdminOperationalReportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminOperationalReportController extends Controller
{
    public function show(AdminOperationalReportRequest $request, AdminOperationalReportService $reports): JsonResponse|StreamedResponse
    {
        if ($request->wantsCsvExport()) {
            $export = $reports->export($request->filters(), $request->exportSection());

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
            'data' => $reports->summary($request->filters()),
        ]);
    }
}
