<?php

namespace App\Modules\DataImport\Controllers;

use App\Modules\DataImport\Models\DataImport;
use App\Modules\DataImport\Models\DataImportEntity;
use App\Modules\DataImport\Requests\StartDataImportRequest;
use App\Modules\DataImport\Resources\DataImportResource;
use App\Modules\DataImport\Resources\DataImportRowResource;
use App\Modules\DataImport\Support\ImportStatus;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class DataImportSessionController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DataImport::class);

        $query = DataImport::query()
            ->with('entities')
            ->latest('id')
            ->limit(50);

        return DataImportResource::collection($query->get());
    }

    public function store(StartDataImportRequest $request): JsonResponse
    {
        $this->authorize('create', DataImport::class);

        $import = DB::transaction(function () use ($request) {
            return DataImport::create([
                'user_id' => $request->user()->id,
                'status' => ImportStatus::SESSION_PENDING,
                'meta' => $request->validated()['meta'] ?? null,
            ]);
        });

        return (new DataImportResource($import->load('entities')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, DataImport $dataImport): DataImportResource
    {
        $this->authorize('view', $dataImport);

        return new DataImportResource($dataImport->load('entities'));
    }

    public function destroy(Request $request, DataImport $dataImport): JsonResponse
    {
        $this->authorize('delete', $dataImport);

        if ($dataImport->isActive()) {
            return response()->json([
                'message' => 'No se puede eliminar una sesion de importacion activa.',
            ], 422);
        }

        $dataImport->delete();

        return response()->json(['message' => 'Sesion eliminada.']);
    }

    public function entityRows(Request $request, DataImport $dataImport, string $entity)
    {
        $this->authorize('view', $dataImport);

        if (! ImportStatus::isValidEntity($entity)) {
            return response()->json(['message' => 'Entidad invalida.'], 422);
        }

        $importEntity = DataImportEntity::query()
            ->where('data_import_id', $dataImport->id)
            ->where('entity', $entity)
            ->firstOrFail();

        $perPage = min((int) $request->query('per_page', 50), 200);

        $rows = $importEntity->rows()
            ->orderBy('row_number')
            ->paginate($perPage);

        return DataImportRowResource::collection($rows);
    }
}
