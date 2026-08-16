<?php

namespace App\Modules\POS\Controllers;

use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Requests\AddPosOrderPaymentsRequest;
use App\Modules\POS\Requests\StorePosCheckoutRequest;
use App\Modules\POS\Requests\StorePosHoldRequest;
use App\Modules\POS\Resources\PosOrderResource;
use App\Modules\POS\Resources\PosOrderSummaryResource;
use App\Modules\POS\Services\PosCheckoutService;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Models\SalePromotionApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class PosOrderController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PosOrder::class);

        $request = request();
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $summary = $request->boolean('summary');

        $query = PosOrder::query()
            ->with([
                'seller',
                'customer',
                'sale',
                'sale.items',
                'sale.items.product',
                'sale.items.warehouse',
                'sale.promotionApplications.items',
                'payments.paymentMethod:id,name',
            ])
            ->when($request->query('status'), fn ($query, string $status) => $query->where('status', $status))
            ->when($sessionId = $request->integer('cash_register_session_id'),
                fn ($query, int $sessionId) => $query->where('cash_register_session_id', $sessionId))
            ->when($cashierId = $request->integer('cashier_id'),
                fn ($query, int $cashierId) => $query->where('cashier_id', $cashierId))
            ->when($customerId = $request->integer('customer_id'),
                fn ($query, int $customerId) => $query->where('customer_id', $customerId))
            ->when($request->query('date_from'),
                fn ($query, string $from) => $query->where('opened_at', '>=', $from))
            ->when($request->query('date_to'),
                fn ($query, string $to) => $query->where('opened_at', '<=', $to))
            ->when($request->query('search'), function ($query, string $search): void {
                $needle = '%'.strtolower($search).'%';
                $query->where(function ($q) use ($needle): void {
                    $q->whereRaw('LOWER(document_number) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(customer_name) LIKE ?', [$needle])
                        ->orWhere('id', is_numeric($search) ? (int) $search : 0);
                });
            })
            ->latest('opened_at');

        $resourceClass = $summary ? PosOrderSummaryResource::class : PosOrderResource::class;
        $paginator = $query->paginate($perPage);

        $this->preloadSerialUnits($paginator->getCollection(), $request);

        return $resourceClass::collection($paginator);
    }

    public function show(PosOrder $posOrder): PosOrderResource
    {
        Gate::authorize('view', $posOrder);

        $posOrder->load(['cashRegisterSession', 'customer', 'sale.customer', 'sale.items.product', 'sale.items.warehouse', 'sale.promotionApplications.items', 'payments.paymentMethod']);
        $this->preloadSerialUnits($posOrder->sale?->items ?? collect(), request());

        return PosOrderResource::make($posOrder);
    }

    public function checkout(StorePosCheckoutRequest $request, PosCheckoutService $checkout): JsonResponse
    {
        Gate::authorize('checkout', PosOrder::class);

        if ($request->filled('promotion_id') || $request->filled('combo_applications') || $request->filled('product_offer_applications')) {
            abort_unless($request->user()?->can('pos.promotions.apply'), Response::HTTP_FORBIDDEN);
        }
        if ($request->filled('invoice_promotion_id') || $request->filled('invoice_promotion_code')) {
            $this->authorizePromotionPermission($request, 'pos.promotions.validate');
        }
        if ($request->filled('promotion_code') || $request->filled('invoice_promotion_code')) {
            abort_unless($request->user()?->can('pos.promotions.code'), Response::HTTP_FORBIDDEN);
        }

        $hasDiscount = collect($request->validated('items', []))->contains(
            fn (array $item): bool => filled($item['discount_type'] ?? null)
                && (float) ($item['discount_value'] ?? 0) > 0,
        );
        if ($hasDiscount) {
            Gate::authorize('discount', PosOrder::class);
        }

        $order = $checkout->checkout(
            cashier: $request->user(),
            cashRegisterSession: CashRegisterSession::query()->findOrFail($request->validated('cash_register_session_id')),
            items: $request->validated('items'),
            payments: $request->validated('payments') ?? [],
            customerId: $request->validated('customer_id'),
            customerName: $request->validated('customer_name'),
            credit: (bool) $request->validated('credit', false),
            creditDueDate: $request->validated('credit_due_date'),
            promotionId: $request->validated('promotion_id'),
            promotionCode: $request->validated('promotion_code'),
            invoicePromotionId: $request->validated('invoice_promotion_id'),
            invoicePromotionCode: $request->validated('invoice_promotion_code'),
            comboApplications: $request->validated('combo_applications', []),
            productOfferApplications: $request->validated('product_offer_applications', []),
        );

        $this->preloadSerialUnits(collect([$order->loadMissing('sale.items')]), $request);

        return PosOrderResource::make($order)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function hold(StorePosHoldRequest $request, PosCheckoutService $checkout): JsonResponse
    {
        Gate::authorize('hold', PosOrder::class);

        if ($request->filled('promotion_id') || $request->filled('combo_applications') || $request->filled('product_offer_applications')) {
            abort_unless($request->user()?->can('pos.promotions.apply'), Response::HTTP_FORBIDDEN);
        }
        if ($request->filled('invoice_promotion_id') || $request->filled('invoice_promotion_code')) {
            $this->authorizePromotionPermission($request, 'pos.promotions.request');
        }
        if ($request->filled('promotion_code') || $request->filled('invoice_promotion_code')) {
            abort_unless($request->user()?->can('pos.promotions.code'), Response::HTTP_FORBIDDEN);
        }

        $hasDiscount = collect($request->validated('items', []))->contains(
            fn (array $item): bool => filled($item['discount_type'] ?? null)
                && (float) ($item['discount_value'] ?? 0) > 0,
        );
        if ($hasDiscount) {
            Gate::authorize('discount', PosOrder::class);
        }

        $order = $checkout->holdOrder(
            seller: $request->user(),
            items: $request->validated('items'),
            customerId: $request->validated('customer_id'),
            customerName: $request->validated('customer_name'),
            promotionId: $request->validated('promotion_id'),
            promotionCode: $request->validated('promotion_code'),
            invoicePromotionId: $request->validated('invoice_promotion_id'),
            invoicePromotionCode: $request->validated('invoice_promotion_code'),
            comboApplications: $request->validated('combo_applications', []),
            productOfferApplications: $request->validated('product_offer_applications', []),
        );

        $this->preloadSerialUnits(collect([$order->loadMissing('sale.items')]), $request);

        return PosOrderResource::make($order)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function addPayments(AddPosOrderPaymentsRequest $request, PosOrder $posOrder, PosCheckoutService $checkout): PosOrderResource
    {
        Gate::authorize('addPayment', $posOrder);

        $hasRequestedInvoicePromotion = $posOrder->sale?->promotionApplications()
            ->where('scope', Promotion::SCOPE_INVOICE)
            ->where('status', SalePromotionApplication::STATUS_REQUESTED)
            ->exists() ?? false;
        if ($hasRequestedInvoicePromotion) {
            $this->authorizePromotionPermission($request, 'pos.promotions.validate');
        }

        $order = $checkout->addPayments(
            order: $posOrder,
            cashier: $request->user(),
            payments: $request->validated('payments'),
            cashRegisterSessionId: $request->validated('cash_register_session_id'),
            chargeItems: $request->validated('items', []),
            invoicePromotionAction: $request->validated('invoice_promotion_action'),
        );

        $this->preloadSerialUnits(collect([$order->loadMissing('sale.items')]), $request);

        return PosOrderResource::make($order);
    }

    public function cancel(PosOrder $posOrder, PosCheckoutService $checkout): PosOrderResource
    {
        Gate::authorize('cancel', $posOrder);

        $order = $checkout->cancelPending(
            order: $posOrder,
            cashier: request()->user(),
        );

        $this->preloadSerialUnits(collect([$order->load('sale.items')]), request());

        return PosOrderResource::make($order);
    }

    /**
     * Pre-carga TODOS los product_units referenciados por los items de
     * las ordenes que se van a serializar, en un solo whereIn, y los
     * expone al SaleItemResource mediante el Request attributes. Asi evitamos
     * N+1 cuando la bandeja de pendientes tiene varias ordenes con items
     * serializados (QW6).
     *
     * El lookup vive en el Request (no en el Resource) para no contaminar
     * estado entre requests en procesos de larga vida.
     *
     * @param  Collection<int, mixed>  $orders
     */
    private function preloadSerialUnits(Collection $orders, Request $request): void
    {
        $unitIds = $orders
            ->flatMap(fn ($order) => $order->sale?->items ?? collect())
            ->flatMap(fn ($item) => $item->product_unit_ids ?? [])
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($unitIds === []) {
            $request->attributes->set('serial_units_lookup', []);

            return;
        }

        $lookup = ProductUnit::query()
            ->whereIn('id', $unitIds)
            ->get(['id', 'serial_type', 'serial_number', 'status'])
            ->mapWithKeys(fn (ProductUnit $unit): array => [
                $unit->id => [
                    'id' => $unit->id,
                    'serial_type' => $unit->serial_type,
                    'serial_number' => $unit->serial_number,
                    'status' => $unit->status,
                ],
            ])
            ->all();

        $request->attributes->set('serial_units_lookup', $lookup);
    }

    private function authorizePromotionPermission(Request $request, string $permission): void
    {
        abort_unless(
            $request->user()?->can($permission) || $request->user()?->can('pos.promotions.apply'),
            Response::HTTP_FORBIDDEN,
        );
    }
}
