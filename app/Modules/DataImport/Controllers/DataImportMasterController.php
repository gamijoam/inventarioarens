<?php

namespace App\Modules\DataImport\Controllers;

use App\Models\User;
use App\Modules\DataImport\Models\DataImport;
use App\Modules\DataImport\Resources\DataImportResource;
use App\Modules\DataImport\Services\DataImportService;
use App\Modules\DataImport\Services\TemplateBuilder;
use App\Modules\DataImport\Support\ImportStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class DataImportMasterController extends Controller
{
    public function __construct(
        private readonly DataImportService $service,
        private readonly TemplateBuilder $templates,
    ) {}

    public function indexSessions(Request $request, Tenant $tenant): JsonResponse
    {
        $sessions = DataImport::query()
            ->where('tenant_id', $tenant->id)
            ->with('entities')
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => DataImportResource::collection($sessions)->resolve(),
        ]);
    }

    public function storeSession(Request $request, Tenant $tenant): JsonResponse
    {
        $session = DB::transaction(function () use ($tenant, $request) {
            return DataImport::create([
                'tenant_id' => $tenant->id,
                'user_id' => $request->user()->id,
                'status' => ImportStatus::SESSION_PENDING,
                'meta' => ['created_by' => 'platform_admin', 'actor_id' => $request->user()->id],
            ]);
        });

        return (new DataImportResource($session->load('entities')))
            ->response()
            ->setStatusCode(201);
    }

    public function uploadFile(Request $request, Tenant $tenant, DataImport $dataImport, string $entity): JsonResponse
    {
        $this->ensureSameTenant($tenant, $dataImport);

        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);

        if (! ImportStatus::isValidEntity($entity)) {
            return response()->json(['message' => 'Entidad invalida.'], 422);
        }

        $entityRow = $this->service->uploadFile($dataImport, $entity, $request->file('file'));

        return response()->json([
            'message' => 'Archivo subido correctamente.',
            'entity' => $entity,
            'source_path' => $entityRow->source_path,
            'session' => new DataImportResource($dataImport->fresh('entities')),
        ]);
    }

    public function runEntity(Request $request, Tenant $tenant, DataImport $dataImport, string $entity): JsonResponse
    {
        $this->ensureSameTenant($tenant, $dataImport);

        if (! ImportStatus::isValidEntity($entity)) {
            return response()->json(['message' => 'Entidad invalida.'], 422);
        }

        $user = $this->resolveMasterActorUser($tenant, $request->user());

        try {
            $entityRow = $this->service->runEntity($dataImport, $entity, $user);
        } catch (\RuntimeException $e) {
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
            ],
            'session' => new DataImportResource($dataImport->fresh('entities')),
        ]);
    }

    public function report(Request $request, Tenant $tenant, DataImport $dataImport)
    {
        $this->ensureSameTenant($tenant, $dataImport);

        $csv = $this->service->generateReport($dataImport);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"import-report-{$dataImport->id}.csv\"",
        ]);
    }

    public function template(Request $request, Tenant $tenant, string $entity)
    {
        if (! ImportStatus::isValidEntity($entity)) {
            return response()->json(['message' => 'Entidad invalida.'], 422);
        }

        $csv = $this->templates->build($entity);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"plantilla_{$entity}.csv\"",
        ]);
    }

    private function ensureSameTenant(Tenant $tenant, DataImport $import): void
    {
        abort_unless($tenant->id === $import->tenant_id, 404, 'Sesion no pertenece al tenant.');
    }

    /**
     * Para master, los imports se firman con un user del tenant (ProductEntryService
     * requiere uno). Usamos el primer admin activo del tenant como actor por defecto.
     */
    private function resolveMasterActorUser(Tenant $tenant, User $platformAdmin): User
    {
        $adminInTenant = User::query()
            ->whereHas('tenants', fn ($q) => $q->where('tenants.id', $tenant->id)->wherePivot('status', 'active'))
            ->first();

        return $adminInTenant ?? $platformAdmin;
    }
}
