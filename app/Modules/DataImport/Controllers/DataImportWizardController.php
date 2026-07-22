<?php

namespace App\Modules\DataImport\Controllers;

use App\Modules\DataImport\Models\DataImport;
use App\Modules\DataImport\Resources\DataImportResource;
use App\Modules\DataImport\Services\DataImportService;
use App\Modules\DataImport\Support\ImportStatus;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class DataImportWizardController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly DataImportService $service) {}

    public function upload(Request $request, DataImport $dataImport, string $entity): JsonResponse
    {
        $this->authorize('execute', $dataImport);

        if (! ImportStatus::isValidEntity($entity)) {
            return response()->json(['message' => 'Entidad invalida.'], 422);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:5120'], // 5 MB
        ]);

        $entityRow = $this->service->uploadFile($dataImport, $entity, $request->file('file'));

        return response()->json([
            'message' => 'Archivo subido correctamente.',
            'entity' => $entity,
            'source_path' => $entityRow->source_path,
            'session' => new DataImportResource($dataImport->fresh('entities')),
        ]);
    }

    public function run(Request $request, DataImport $dataImport, string $entity): JsonResponse
    {
        $this->authorize('execute', $dataImport);

        if (! ImportStatus::isValidEntity($entity)) {
            return response()->json(['message' => 'Entidad invalida.'], 422);
        }

        try {
            $entityRow = $this->service->runEntity($dataImport, $entity, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Importacion de {$entity} finalizada.",
            'entity' => $entity,
            'summary' => [
                'total' => $entityRow->total_rows,
                'ok' => $entityRow->succeeded_rows,
                'skipped' => $entityRow->skipped_rows,
                'failed' => $entityRow->failed_rows,
                'status' => $entityRow->status,
                'error_summary' => $entityRow->error_summary,
            ],
            'session' => new DataImportResource($dataImport->fresh('entities')),
        ]);
    }

    public function report(Request $request, DataImport $dataImport): Response
    {
        $this->authorize('view', $dataImport);

        $csv = $this->service->generateReport($dataImport);

        $filename = "import-report-{$dataImport->id}.csv";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
