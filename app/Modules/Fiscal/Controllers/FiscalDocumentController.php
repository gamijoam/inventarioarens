<?php

namespace App\Modules\Fiscal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Requests\CreateFiscalDocumentPreviewRequest;
use App\Modules\Fiscal\Resources\FiscalDocumentResource;
use App\Modules\Fiscal\Services\FiscalDocumentPreviewService;
use App\Modules\Sales\Models\Sale;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class FiscalDocumentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);

        $filters = $request->validate([
            'sale_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:preview'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = FiscalDocument::query()->with('items');

        if (isset($filters['sale_id'])) {
            $query->where('sale_id', $filters['sale_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from'])) {
            $query->whereDate('snapshot_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('snapshot_at', '<=', $filters['date_to']);
        }

        return FiscalDocumentResource::collection(
            $query->latest('snapshot_at')->paginate($filters['per_page'] ?? 25)->withQueryString(),
        );
    }

    public function preview(
        CreateFiscalDocumentPreviewRequest $request,
        FiscalDocumentPreviewService $previews,
    ): JsonResponse {
        $sale = Sale::query()->findOrFail($request->integer('sale_id'));
        [$document, $created] = $previews->createFromSale($sale, $request->user());

        return FiscalDocumentResource::make($document)
            ->response()
            ->setStatusCode($created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function show(Request $request, FiscalDocument $fiscalDocument): FiscalDocumentResource
    {
        $this->authorizeView($request);

        return FiscalDocumentResource::make($fiscalDocument->load('items'));
    }

    private function authorizeView(Request $request): void
    {
        $tenant = app(TenantManager::class)->require();
        setPermissionsTeamId($tenant->id);

        abort_unless(
            $request->user()?->can('sales.view')
                || $request->user()?->can('reports.view')
                || $request->user()?->can('reports.sales.view'),
            Response::HTTP_FORBIDDEN,
        );
    }
}
