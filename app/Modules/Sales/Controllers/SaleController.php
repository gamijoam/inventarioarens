<?php

namespace App\Modules\Sales\Controllers;

use App\Modules\AccessControl\Services\ScopeResolver;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Requests\StoreSaleRequest;
use App\Modules\Sales\Resources\SaleResource;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SaleController extends Controller
{
    public function __construct(private readonly ScopeResolver $scopes) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Sale::class);

        $request = request();
        $query = Sale::query();

        if ($status = $request->string('status')->trim()->toString()) {
            if (in_array($status, [Sale::STATUS_DRAFT, Sale::STATUS_CONFIRMED, Sale::STATUS_CANCELLED], true)) {
                $query->where('status', $status);
            }
        }

        if ($customerId = $request->integer('customer_id')) {
            $query->where('customer_id', $customerId);
        }

        $promotionScope = $request->string('promotion_scope')->trim()->toString();
        if ($promotionScope === 'none') {
            $query->whereDoesntHave('promotionApplications');
        } elseif (in_array($promotionScope, [
            'any',
            Promotion::SCOPE_INVOICE,
            Promotion::SCOPE_COMBO,
            Promotion::SCOPE_PRODUCT_OFFER,
            Promotion::SCOPE_LEGACY_PRODUCT_DISCOUNT,
        ], true)) {
            $query->whereHas(
                'promotionApplications',
                fn ($applicationQuery) => $promotionScope === 'any'
                    ? $applicationQuery
                    : $applicationQuery->where('scope', $promotionScope),
            );
        }

        if ($dateFrom = $request->date('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->date('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($search): void {
                $cleanNumeric = ltrim($search, '#');
                if (is_numeric($cleanNumeric)) {
                    $q->orWhere('id', (int) $cleanNumeric);
                }

                $like = "%{$search}%";

                $q->orWhereHas('customer', function ($customerQuery) use ($like): void {
                    $customerQuery
                        ->whereRaw('LOWER(COALESCE(name, \'\')) LIKE LOWER(?)', [$like])
                        ->orWhereRaw('LOWER(COALESCE(document_number, \'\')) LIKE LOWER(?)', [$like])
                        ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE LOWER(?)', [$like])
                        ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE LOWER(?)', [$like]);
                });

                $q->orWhereHas('posOrder', function ($posQuery) use ($like): void {
                    $posQuery->whereRaw('LOWER(COALESCE(customer_name, \'\')) LIKE LOWER(?)', [$like]);
                });

                $q->orWhereHas('receivable', function ($rQuery) use ($like): void {
                    $rQuery->whereRaw('LOWER(COALESCE(document_number, \'\')) LIKE LOWER(?)', [$like]);
                });

                $q->orWhereHas('items.product', function ($productQuery) use ($like): void {
                    $productQuery
                        ->whereRaw('LOWER(COALESCE(name, \'\')) LIKE LOWER(?)', [$like])
                        ->orWhereRaw('LOWER(COALESCE(sku, \'\')) LIKE LOWER(?)', [$like])
                        ->orWhereRaw('LOWER(COALESCE(barcode, \'\')) LIKE LOWER(?)', [$like]);
                });
            });
        }

        if ($branchIds = $this->scopes->branchIdsFor($request->user())) {
            $query->whereHas('items.warehouse', fn ($warehouseQuery) => $warehouseQuery->whereIn('branch_id', $branchIds));
        }

        $query = $this->scopes->applyVendorScope($query, $request->user(), 'created_by');

        $summaryRow = (clone $query)
            ->selectRaw("
                COUNT(*) as total_sales_count,
                COALESCE(SUM(CASE WHEN status = 'confirmed' THEN total_base_amount ELSE 0 END), 0) as confirmed_base_total,
                COALESCE(SUM(CASE WHEN status = 'confirmed' THEN total_local_amount ELSE 0 END), 0) as confirmed_local_total,
                COALESCE(COUNT(CASE WHEN status = 'confirmed' THEN 1 END), 0) as confirmed_count,
                COALESCE(COUNT(CASE WHEN status = 'draft' THEN 1 END), 0) as draft_count,
                COALESCE(COUNT(CASE WHEN status = 'cancelled' THEN 1 END), 0) as cancelled_count
            ")
            ->first();

        $matchingSaleIds = (clone $query)
            ->where('status', Sale::STATUS_CONFIRMED)
            ->select('sales.id');

        $refundRow = DB::table('sales_returns')
            ->whereIn('sale_id', $matchingSaleIds)
            ->where('status', 'processed')
            ->selectRaw('
                COALESCE(SUM(refund_amount_base), 0) as refund_base_total,
                COALESCE(SUM(refund_amount_local), 0) as refund_local_total,
                COUNT(*) as refund_count
            ')
            ->first();

        $posCount = DB::table('pos_orders')
            ->whereIn('sale_id', (clone $query)->select('sales.id'))
            ->count();

        $confirmedBaseTotal = (float) ($summaryRow->confirmed_base_total ?? 0);
        $confirmedLocalTotal = (float) ($summaryRow->confirmed_local_total ?? 0);
        $refundBaseTotal = (float) ($refundRow->refund_base_total ?? 0);
        $refundLocalTotal = (float) ($refundRow->refund_local_total ?? 0);
        $refundCount = (int) ($refundRow->refund_count ?? 0);

        $netBaseTotal = max(0, $confirmedBaseTotal - $refundBaseTotal);
        $netLocalTotal = max(0, $confirmedLocalTotal - $refundLocalTotal);

        $canViewCosts = (bool) ($request->user()?->can('finance.costs.view') ?? false);
        $confirmedCostBaseTotal = 0.0;
        $confirmedProfitBaseTotal = 0.0;
        $confirmedProfitMarginPercent = 0.0;

        if ($canViewCosts) {
            $costRow = DB::table('sale_items')
                ->join('products', 'products.id', '=', 'sale_items.product_id')
                ->whereIn('sale_items.sale_id', $matchingSaleIds)
                ->selectRaw('
                    COALESCE(SUM(
                        sale_items.quantity * COALESCE(sale_items.base_unit_cost, products.last_purchase_cost, products.average_cost, 0)
                    ), 0) as total_cost
                ')
                ->first();

            $confirmedCostBaseTotal = (float) ($costRow->total_cost ?? 0);
            $confirmedProfitBaseTotal = round($confirmedBaseTotal - $confirmedCostBaseTotal, 4);
            $confirmedProfitMarginPercent = $confirmedBaseTotal > 0
                ? round(($confirmedProfitBaseTotal / $confirmedBaseTotal) * 100, 2)
                : 0.0;
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $paginated = (clone $query)
            ->with([
                'customer',
                'creator',
                'receivable',
                'posOrder.cashier',
                'posOrder.cashRegisterSession.branch',
                'posOrder.cashRegisterSession.cashRegister',
                'posOrder.payments.paymentMethod',
                'items.product',
                'items.variant',
                'items.warehouse',
                'salesReturns.items',
                'promotionApplications.items',
            ])
            ->withCount('items')
            ->latest()
            ->paginate($perPage);

        return SaleResource::collection($paginated)->additional([
            'summary' => [
                'total_count' => (int) ($summaryRow->total_sales_count ?? 0),
                'confirmed_base_total' => round($confirmedBaseTotal, 4),
                'confirmed_local_total' => round($confirmedLocalTotal, 4),
                'net_base_total' => round($netBaseTotal, 4),
                'net_local_total' => round($netLocalTotal, 4),
                'refund_base_total' => round($refundBaseTotal, 4),
                'refund_local_total' => round($refundLocalTotal, 4),
                'refund_count' => $refundCount,
                'confirmed_count' => (int) ($summaryRow->confirmed_count ?? 0),
                'draft_count' => (int) ($summaryRow->draft_count ?? 0),
                'cancelled_count' => (int) ($summaryRow->cancelled_count ?? 0),
                'pos_count' => $posCount,
                'confirmed_cost_base_total' => $canViewCosts ? round($confirmedCostBaseTotal, 4) : null,
                'confirmed_profit_base_total' => $canViewCosts ? round($confirmedProfitBaseTotal, 4) : null,
                'confirmed_profit_margin_percent' => $canViewCosts ? $confirmedProfitMarginPercent : null,
            ],
        ]);
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
            'items.variant',
            'items.warehouse',
            'items.stockMovement',
            'salesReturns.items',
            'promotionApplications.items',
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
