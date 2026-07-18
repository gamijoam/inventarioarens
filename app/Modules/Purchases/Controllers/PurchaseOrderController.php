<?php

namespace App\Modules\Purchases\Controllers;

use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Requests\ReceivePurchaseOrderRequest;
use App\Modules\Purchases\Requests\StorePurchaseOrderRequest;
use App\Modules\Purchases\Resources\PurchaseOrderResource;
use App\Modules\Purchases\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([
                'all',
                PurchaseOrder::STATUS_DRAFT,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                PurchaseOrder::STATUS_RECEIVED,
                PurchaseOrder::STATUS_CANCELLED,
            ])],
            'supplier_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $normalizedSearch = mb_strtolower($search);
        $limit = min(max((int) ($filters['limit'] ?? 25), 1), 100);

        return PurchaseOrderResource::collection(
            PurchaseOrder::query()
                ->with(['supplier', 'accountPayable'])
                ->withCount('items')
                ->when($search !== '', function ($query) use ($normalizedSearch): void {
                    $query->where(function ($query) use ($normalizedSearch): void {
                        $query
                            ->whereRaw('LOWER(COALESCE(document_number, \'\')) LIKE ?', ["%{$normalizedSearch}%"])
                            ->orWhereHas('supplier', function ($supplier) use ($normalizedSearch): void {
                                $supplier
                                    ->whereRaw('LOWER(name) LIKE ?', ["%{$normalizedSearch}%"])
                                    ->orWhereRaw('LOWER(COALESCE(document_number, \'\')) LIKE ?', ["%{$normalizedSearch}%"]);
                            });
                    });
                })
                ->when(($filters['status'] ?? 'all') !== 'all', function ($query) use ($filters): void {
                    $query->where('status', $filters['status']);
                })
                ->when(! empty($filters['supplier_id']), function ($query) use ($filters): void {
                    $query->where('supplier_id', $filters['supplier_id']);
                })
                ->when(! empty($filters['date_from']), function ($query) use ($filters): void {
                    $query->whereDate('issued_at', '>=', $filters['date_from']);
                })
                ->when(! empty($filters['date_to']), function ($query) use ($filters): void {
                    $query->whereDate('issued_at', '<=', $filters['date_to']);
                })
                ->latest()
                ->paginate($limit)
        );
    }

    public function store(StorePurchaseOrderRequest $request, PurchaseOrderService $purchases): JsonResponse
    {
        Gate::authorize('create', PurchaseOrder::class);

        $purchaseOrder = $purchases->createDraft($request->user(), $request->validated());

        return PurchaseOrderResource::make($purchaseOrder)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        Gate::authorize('view', $purchaseOrder);

        return PurchaseOrderResource::make(
            $purchaseOrder->load(['supplier', 'accountPayable', 'items.product', 'items.warehouse', 'items.stockMovement'])
        );
    }

    public function receive(
        ReceivePurchaseOrderRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $purchases,
    ): PurchaseOrderResource {
        Gate::authorize('receive', $purchaseOrder);

        return PurchaseOrderResource::make(
            $purchases->receive($purchaseOrder, $request->user(), $request->validated())->load('accountPayable')
        );
    }

    public function cancel(PurchaseOrder $purchaseOrder, PurchaseOrderService $purchases): PurchaseOrderResource
    {
        Gate::authorize('cancel', $purchaseOrder);

        return PurchaseOrderResource::make($purchases->cancelDraft($purchaseOrder));
    }
}
