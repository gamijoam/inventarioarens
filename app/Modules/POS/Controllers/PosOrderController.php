<?php

namespace App\Modules\POS\Controllers;

use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Requests\AddPosOrderPaymentsRequest;
use App\Modules\POS\Requests\StorePosCheckoutRequest;
use App\Modules\POS\Resources\PosOrderResource;
use App\Modules\POS\Resources\PosOrderSummaryResource;
use App\Modules\POS\Services\PosCheckoutService;
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
                'customer',
                'sale',
                'sale.items',
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

        $posOrder->load(['cashRegisterSession', 'customer', 'sale.customer', 'sale.items.product', 'sale.items.warehouse', 'payments.paymentMethod']);
        $this->preloadSerialUnits($posOrder->sale?->items ?? collect(), request());

        return PosOrderResource::make($posOrder);
    }

    public function checkout(StorePosCheckoutRequest $request, PosCheckoutService $checkout): JsonResponse
    {
        Gate::authorize('checkout', PosOrder::class);

        $order = $checkout->checkout(
            cashier: $request->user(),
            cashRegisterSession: CashRegisterSession::query()->findOrFail($request->validated('cash_register_session_id')),
            items: $request->validated('items'),
            payments: $request->validated('payments') ?? [],
            customerId: $request->validated('customer_id'),
            customerName: $request->validated('customer_name'),
            credit: (bool) $request->validated('credit', false),
            creditDueDate: $request->validated('credit_due_date')
        );

        $this->preloadSerialUnits(collect([$order->load('sale.items')]), $request);

        return PosOrderResource::make($order)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function addPayments(AddPosOrderPaymentsRequest $request, PosOrder $posOrder, PosCheckoutService $checkout): PosOrderResource
    {
        Gate::authorize('addPayment', $posOrder);

        $order = $checkout->addPayments(
            order: $posOrder,
            cashier: $request->user(),
            payments: $request->validated('payments'),
        );

        $this->preloadSerialUnits(collect([$order->load('sale.items')]), $request);

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
}
