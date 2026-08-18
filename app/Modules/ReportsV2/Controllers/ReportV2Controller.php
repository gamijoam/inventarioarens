<?php

namespace App\Modules\ReportsV2\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ReportsV2\ReportDefinition;
use App\Modules\ReportsV2\ReportRegistry;
use App\Modules\ReportsV2\Requests\ReportExportRequest;
use App\Modules\ReportsV2\Requests\ReportV2CatalogRequest;
use App\Modules\ReportsV2\Requests\ReportV2Request;
use App\Modules\ReportsV2\Services\ReportExportService;
use App\Modules\ReportsV2\Services\ReportQueryService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class ReportV2Controller extends Controller
{
    public function catalog(ReportV2CatalogRequest $request, ReportRegistry $registry): JsonResponse
    {
        $user = $request->user();

        $reports = collect($registry->all())
            ->filter(fn (ReportDefinition $definition): bool => $user?->can($definition->permission) ?? false)
            ->map(fn (ReportDefinition $definition): array => [
                'code' => $definition->code,
                'name' => $definition->name,
                'domain' => $definition->domain,
                'default_dimension' => $definition->defaultDimension,
                'default_measure' => $definition->defaultMeasure,
                'dimensions' => array_keys($definition->dimensions),
                'measures' => array_keys($definition->measures),
                'org_supported' => $definition->orgSupported,
                'has_warehouse_filter' => isset($definition->equalityFilters['warehouse_id']),
                'has_low_stock_filter' => $definition->lowStockFilter,
                'has_local_amounts' => $definition->localPairs !== [],
                'date_range_required' => $definition->dateColumn !== null,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $reports]);
    }

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

    public function export(string $report, ReportExportRequest $request, ReportExportService $service): Response
    {
        try {
            return $service->export($report, $request->filters(), $request->exportFormat());
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }
    }
}
