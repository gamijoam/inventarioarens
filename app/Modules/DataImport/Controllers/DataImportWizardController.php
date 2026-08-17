<?php

namespace App\Modules\DataImport\Controllers;

use App\Modules\DataImport\Jobs\RunDataImportEntity;
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
            'file' => ['required', 'file', 'max:'.((int) config('data_import.max_file_mb', 50) * 1024)],
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
            $this->service->prepareEntityForQueue($dataImport, $entity);
            RunDataImportEntity::dispatch(
                $dataImport->id,
                $entity,
                $request->user()->id,
                $dataImport->tenant_id,
            );
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Importacion de {$entity} encolada.",
            'entity' => $entity,
            'session' => new DataImportResource($dataImport->fresh('entities')),
        ], 202);
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
