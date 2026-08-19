<?php

namespace App\Modules\Quotations\Controllers;

use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Requests\StoreQuotationRequest;
use App\Modules\Quotations\Requests\UpdateQuotationRequest;
use App\Modules\Quotations\Resources\QuotationResource;
use App\Modules\Quotations\Services\QuotationService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Quotation::class);

        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));

        $query = Quotation::query()
            ->with(['items', 'customer', 'warehouse', 'creator'])
            ->when($request->query('status'),
                fn ($query, string $status) => $query->where('status', $status))
            ->when($request->query('customer_id'),
                fn ($query, int $customerId) => $query->where('customer_id', $customerId))
            ->when($request->query('date_from'),
                fn ($query, string $from) => $query->where('created_at', '>=', $from))
            ->when($request->query('date_to'),
                fn ($query, string $to) => $query->where('created_at', '<=', $to))
            ->when($request->query('search'), function ($query, string $search): void {
                $needle = '%'.strtolower($search).'%';
                $query->where(function ($q) use ($needle, $search): void {
                    $q->whereRaw('LOWER(document_number) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(customer_name) LIKE ?', [$needle])
                        ->orWhere('id', is_numeric($search) ? (int) $search : 0);
                });
            })
            ->latest('id');

        return QuotationResource::collection($query->paginate($perPage));
    }

    public function store(StoreQuotationRequest $request): JsonResponse
    {
        Gate::authorize('create', Quotation::class);

        $quotation = $this->service->create($request->user(), $request->validated());

        return response()->json(['data' => QuotationResource::make($quotation)], 201);
    }

    public function show(Quotation $quotation): QuotationResource
    {
        $this->abortIfForeign($quotation);
        Gate::authorize('view', $quotation);

        return QuotationResource::make($quotation->load(['items', 'customer', 'warehouse', 'creator']));
    }

    public function update(UpdateQuotationRequest $request, Quotation $quotation): QuotationResource
    {
        $this->abortIfForeign($quotation);
        Gate::authorize('update', $quotation);

        return QuotationResource::make($this->service->update($request->user(), $quotation, $request->validated()));
    }

    public function destroy(Request $request, Quotation $quotation): JsonResponse
    {
        $this->abortIfForeign($quotation);
        Gate::authorize('delete', $quotation);

        $quotation = $this->service->cancel($request->user(), $quotation);

        return response()->json(['data' => QuotationResource::make($quotation)]);
    }

    public function convert(Request $request, Quotation $quotation): JsonResponse
    {
        $this->abortIfForeign($quotation);
        Gate::authorize('convert', $quotation);

        $result = $this->service->convert($request->user(), $quotation);

        return response()->json([
            'data' => [
                'quotation' => $result['quotation']->resolve(),
                'pos_order' => [
                    'id' => $result['pos_order']->id,
                    'status' => $result['pos_order']->status,
                    'document_number' => $result['pos_order']->document_number,
                ],
            ],
        ]);
    }

    public function pdf(Request $request, Quotation $quotation): Response
    {
        $this->abortIfForeign($quotation);
        Gate::authorize('view', $quotation);

        $bytes = $this->service->renderPdf($quotation);
        $filename = sprintf('cotizacion-%s-%s.pdf', $quotation->document_number, now()->format('Ymd-His'));

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Content-Length' => (string) strlen($bytes),
        ]);
    }

    public function pdfHtml(Request $request, Quotation $quotation): Response
    {
        $this->abortIfForeign($quotation);
        Gate::authorize('view', $quotation);

        return response($this->service->renderHtml($quotation), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * Oculta cotizaciones de otros tenants (404 en lugar de 403) para no
     * revelar su existencia.
     */
    private function abortIfForeign(Quotation $quotation): void
    {
        $currentTenantId = app(TenantManager::class)->current()?->id;
        abort_unless(
            $currentTenantId !== null && (int) $quotation->tenant_id === (int) $currentTenantId,
            404,
            'Cotizacion no encontrada.',
        );
    }
}
