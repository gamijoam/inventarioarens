<?php

namespace App\Modules\POS\Controllers;

use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Requests\AddPosOrderPaymentsRequest;
use App\Modules\POS\Requests\StorePosCheckoutRequest;
use App\Modules\POS\Resources\PosOrderResource;
use App\Modules\POS\Services\PosCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class PosOrderController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PosOrder::class);

        return PosOrderResource::collection(
            PosOrder::query()
                ->with(['cashRegisterSession', 'customer', 'sale.items.product', 'payments'])
                ->when(request('status'), fn ($query, string $status) => $query->where('status', $status))
                ->latest()
                ->paginate(25)
        );
    }

    public function checkout(StorePosCheckoutRequest $request, PosCheckoutService $checkout): JsonResponse
    {
        Gate::authorize('checkout', PosOrder::class);

        $order = $checkout->checkout(
            cashier: $request->user(),
            cashRegisterSession: CashRegisterSession::query()->findOrFail($request->validated('cash_register_session_id')),
            items: $request->validated('items'),
            payments: $request->validated('payments'),
            customerId: $request->validated('customer_id'),
            customerName: $request->validated('customer_name')
        );

        return PosOrderResource::make($order)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(PosOrder $posOrder): PosOrderResource
    {
        Gate::authorize('view', $posOrder);

        return PosOrderResource::make($posOrder->load(['cashRegisterSession', 'customer', 'sale.customer', 'sale.items.product', 'sale.items.warehouse', 'payments']));
    }

    public function addPayments(AddPosOrderPaymentsRequest $request, PosOrder $posOrder, PosCheckoutService $checkout): PosOrderResource
    {
        Gate::authorize('addPayment', $posOrder);

        $order = $checkout->addPayments(
            order: $posOrder,
            cashier: $request->user(),
            payments: $request->validated('payments'),
        );

        return PosOrderResource::make($order);
    }

    public function cancel(PosOrder $posOrder, PosCheckoutService $checkout): PosOrderResource
    {
        Gate::authorize('cancel', $posOrder);

        $order = $checkout->cancelPending(
            order: $posOrder,
            cashier: request()->user(),
        );

        return PosOrderResource::make($order);
    }
}
