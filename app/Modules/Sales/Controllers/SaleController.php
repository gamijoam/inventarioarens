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
            $tokens = array_values(array_filter(
                preg_split('/\s+/', $search) ?: [],
                fn ($token) => mb_strlen($token) > 0
            ));

            $isPgsql = DB::connection()->getDriverName() === 'pgsql';
            $termExpr = $isPgsql ? 'unaccent(LOWER(?))' : 'LOWER(?)';

            $query->where(function ($q) use ($search, $tokens, $isPgsql, $termExpr): void {
                $cleanNumeric = ltrim($search, '#');
                if (is_numeric($cleanNumeric)) {
                    $q->orWhere('sales.id', (int) $cleanNumeric);
                }

                // Coincidencia exacta/completa de la frase ingresada
                $like = "%{$search}%";

                $nameExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(name, \'\')))' : 'LOWER(COALESCE(name, \'\'))';
                $skuExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(sku, \'\')))' : 'LOWER(COALESCE(sku, \'\'))';
                $barcodeExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(barcode, \'\')))' : 'LOWER(COALESCE(barcode, \'\'))';

                $custNameExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(name, \'\')))' : 'LOWER(COALESCE(name, \'\'))';
                $custDocExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(document_number, \'\')))' : 'LOWER(COALESCE(document_number, \'\'))';
                $custEmailExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(email, \'\')))' : 'LOWER(COALESCE(email, \'\'))';
                $custPhoneExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(phone, \'\')))' : 'LOWER(COALESCE(phone, \'\'))';

                $posNameExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(customer_name, \'\')))' : 'LOWER(COALESCE(customer_name, \'\'))';
                $recDocExpr = $isPgsql ? 'unaccent(LOWER(COALESCE(document_number, \'\')))' : 'LOWER(COALESCE(document_number, \'\'))';

                $q->orWhere(function ($sub) use ($like, $termExpr, $nameExpr, $skuExpr, $barcodeExpr, $custNameExpr, $custDocExpr, $custEmailExpr, $custPhoneExpr, $posNameExpr, $recDocExpr): void {
                    $sub->whereHas('customer', function ($customerQuery) use ($like, $termExpr, $custNameExpr, $custDocExpr, $custEmailExpr, $custPhoneExpr): void {
                        $customerQuery
                            ->whereRaw("{$custNameExpr} LIKE {$termExpr}", [$like])
                            ->orWhereRaw("{$custDocExpr} LIKE {$termExpr}", [$like])
                            ->orWhereRaw("{$custEmailExpr} LIKE {$termExpr}", [$like])
                            ->orWhereRaw("{$custPhoneExpr} LIKE {$termExpr}", [$like]);
                    })
                    ->orWhereHas('posOrder', function ($posQuery) use ($like, $termExpr, $posNameExpr): void {
                        $posQuery->whereRaw("{$posNameExpr} LIKE {$termExpr}", [$like]);
                    })
                    ->orWhereHas('receivable', function ($rQuery) use ($like, $termExpr, $recDocExpr): void {
                        $rQuery->whereRaw("{$recDocExpr} LIKE {$termExpr}", [$like]);
                    })
                    ->orWhereHas('items.product', function ($productQuery) use ($like, $termExpr, $nameExpr, $skuExpr, $barcodeExpr): void {
                        $productQuery
                            ->whereRaw("{$nameExpr} LIKE {$termExpr}", [$like])
                            ->orWhereRaw("{$skuExpr} LIKE {$termExpr}", [$like])
                            ->orWhereRaw("{$barcodeExpr} LIKE {$termExpr}", [$like]);
                    });
                });

                // Si hay más de una palabra, buscar que todos los tokens coincidan dentro del mismo elemento
                // (evita que un token haga match en el Producto A y otro token en el Producto B de la misma venta)
                if (count($tokens) > 1) {
                    $q->orWhere(function ($multiWordQuery) use ($tokens, $termExpr, $nameExpr, $skuExpr, $barcodeExpr, $custNameExpr, $custDocExpr, $custEmailExpr, $custPhoneExpr, $posNameExpr, $recDocExpr, $isPgsql): void {
                        // 1. Todos los tokens coinciden en el MISMO producto vendido
                        $multiWordQuery->whereHas('items.product', function ($productQuery) use ($tokens, $termExpr, $nameExpr, $skuExpr, $barcodeExpr): void {
                            foreach ($tokens as $token) {
                                $tokenLike = "%{$token}%";
                                $productQuery->where(function ($pq) use ($tokenLike, $termExpr, $nameExpr, $skuExpr, $barcodeExpr): void {
                                    $pq->whereRaw("{$nameExpr} LIKE {$termExpr}", [$tokenLike])
                                        ->orWhereRaw("{$skuExpr} LIKE {$termExpr}", [$tokenLike])
                                        ->orWhereRaw("{$barcodeExpr} LIKE {$termExpr}", [$tokenLike]);
                                });
                            }
                        })
                        // 2. O todos los tokens coinciden en el cliente
                        ->orWhereHas('customer', function ($customerQuery) use ($tokens, $termExpr, $custNameExpr, $custDocExpr, $custEmailExpr, $custPhoneExpr): void {
                            foreach ($tokens as $token) {
                                $tokenLike = "%{$token}%";
                                $customerQuery->where(function ($cq) use ($tokenLike, $termExpr, $custNameExpr, $custDocExpr, $custEmailExpr, $custPhoneExpr): void {
                                    $cq->whereRaw("{$custNameExpr} LIKE {$termExpr}", [$tokenLike])
                                        ->orWhereRaw("{$custDocExpr} LIKE {$termExpr}", [$tokenLike])
                                        ->orWhereRaw("{$custEmailExpr} LIKE {$termExpr}", [$tokenLike])
                                        ->orWhereRaw("{$custPhoneExpr} LIKE {$termExpr}", [$tokenLike]);
                                });
                            }
                        })
                        // 3. O todos los tokens coinciden en el nombre del cliente POS
                        ->orWhereHas('posOrder', function ($posQuery) use ($tokens, $termExpr, $posNameExpr): void {
                            foreach ($tokens as $token) {
                                $tokenLike = "%{$token}%";
                                $posQuery->whereRaw("{$posNameExpr} LIKE {$termExpr}", [$tokenLike]);
                            }
                        })
                        // 4. O todos los tokens coinciden en la cuenta por cobrar
                        ->orWhereHas('receivable', function ($rQuery) use ($tokens, $termExpr, $recDocExpr): void {
                            foreach ($tokens as $token) {
                                $tokenLike = "%{$token}%";
                                $rQuery->whereRaw("{$recDocExpr} LIKE {$termExpr}", [$tokenLike]);
                            }
                        })
                        // 5. O coincidencia combinada Cliente + Producto en el MISMO producto (ej. "Carlos Freno")
                        ->orWhere(function ($crossQuery) use ($tokens, $termExpr, $isPgsql): void {
                            $crossQuery->whereHas('items.product', function ($itemProductQuery) use ($tokens, $termExpr, $isPgsql): void {
                                $pName = $isPgsql ? 'unaccent(LOWER(COALESCE(products.name, \'\')))' : 'LOWER(COALESCE(products.name, \'\'))';
                                $pSku = $isPgsql ? 'unaccent(LOWER(COALESCE(products.sku, \'\')))' : 'LOWER(COALESCE(products.sku, \'\'))';
                                $cName = $isPgsql ? 'unaccent(LOWER(COALESCE(c_sub.name, \'\')))' : 'LOWER(COALESCE(c_sub.name, \'\'))';
                                $poName = $isPgsql ? 'unaccent(LOWER(COALESCE(po_sub.customer_name, \'\')))' : 'LOWER(COALESCE(po_sub.customer_name, \'\'))';

                                foreach ($tokens as $token) {
                                    $tokenLike = "%{$token}%";
                                    $itemProductQuery->where(function ($tokenQ) use ($tokenLike, $termExpr, $pName, $pSku, $cName, $poName): void {
                                        $tokenQ->whereRaw("{$pName} LIKE {$termExpr}", [$tokenLike])
                                            ->orWhereRaw("{$pSku} LIKE {$termExpr}", [$tokenLike])
                                            ->orWhereRaw("EXISTS (SELECT 1 FROM customers c_sub WHERE c_sub.id = sales.customer_id AND {$cName} LIKE {$termExpr})", [$tokenLike])
                                            ->orWhereRaw("EXISTS (SELECT 1 FROM pos_orders po_sub WHERE po_sub.sale_id = sales.id AND {$poName} LIKE {$termExpr})", [$tokenLike]);
                                    });
                                }
                            });
                        });
                    });
                }
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
                'salesReturns.items.product',
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
            'salesReturns.items.product',
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
