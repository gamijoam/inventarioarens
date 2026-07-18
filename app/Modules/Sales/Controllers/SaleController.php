<?php

namespace App\Modules\Sales\Controllers;

use App\Modules\AccessControl\Services\ScopeResolver;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Requests\StoreSaleRequest;
use App\Modules\Sales\Resources\SaleResource;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class SaleController extends Controller
{
    public function __construct(private readonly ScopeResolver $scopes) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Sale::class);

        $request = request();
        $query = Sale::query()
            ->with([
                'customer',
                'creator',
                'receivable',
                'posOrder.cashier',
                'posOrder.cashRegisterSession.branch',
                'posOrder.cashRegisterSession.cashRegister',
                'posOrder.payments.paymentMethod',
                'items.product',
                'items.warehouse',
            ])
            ->withCount('items')
            ->latest();

        if ($status = $request->string('status')->trim()->toString()) {
            if (in_array($status, [Sale::STATUS_DRAFT, Sale::STATUS_CONFIRMED, Sale::STATUS_CANCELLED], true)) {
                $query->where('status', $status);
            }
        }

        if ($customerId = $request->integer('customer_id')) {
            $query->where('customer_id', $customerId);
        }

        if ($dateFrom = $request->date('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->date('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($search): void {
                if (is_numeric($search)) {
                    $q->orWhere('id', (int) $search);
                }

                $q->orWhereHas('customer', function ($customerQuery) use ($search): void {
                    $customerQuery
                        ->where('name', 'ilike', "%{$search}%")
                        ->orWhere('document_number', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%")
                        ->orWhere('phone', 'ilike', "%{$search}%");
                });

                $q->orWhereHas('items.product', function ($productQuery) use ($search): void {
                    $productQuery
                        ->where('name', 'ilike', "%{$search}%")
                        ->orWhere('sku', 'ilike', "%{$search}%")
                        ->orWhere('barcode', 'ilike', "%{$search}%");
                });
            });
        }

        if ($branchIds = $this->scopes->branchIdsFor($request->user())) {
            $query->whereHas('items.warehouse', fn ($warehouseQuery) => $warehouseQuery->whereIn('branch_id', $branchIds));
        }

        $query = $this->scopes->applyVendorScope($query, $request->user(), 'created_by');
        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return SaleResource::collection($query->paginate($perPage));
    }

    public function store(StoreSaleRequest $request, SaleService $sales): JsonResponse
    {
        Gate::authorize('create', Sale::class);

        $sale = $sales->createDraft(
            user: $request->user(),
            items: $request->validated('items'),
            customerId: $request->validated('customer_id')
        );

        return SaleResource::make($sale)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Sale $sale): SaleResource
    {
        Gate::authorize('view', $sale);

        return SaleResource::make($sale->load([
            'customer',
            'creator',
            'receivable.payments',
            'posOrder.cashier',
            'posOrder.cashRegisterSession.branch',
            'posOrder.cashRegisterSession.cashRegister',
            'posOrder.payments.paymentMethod',
            'items.product',
            'items.warehouse',
            'items.stockMovement',
        ]));
    }

    public function confirm(Sale $sale, SaleService $sales): SaleResource
    {
        Gate::authorize('confirm', $sale);

        return SaleResource::make($sales->confirm($sale, request()->user()));
    }

    public function cancel(Sale $sale, SaleService $sales): SaleResource
    {
        Gate::authorize('cancel', $sale);

        return SaleResource::make($sales->cancelDraft($sale));
    }
}
