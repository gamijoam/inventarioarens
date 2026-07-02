<?php

namespace App\Modules\POS\Controllers;

use App\Modules\POS\Models\PosOrder;
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
                ->with(['sale.items.product', 'payments'])
                ->latest()
                ->paginate(25)
        );
    }

    public function checkout(StorePosCheckoutRequest $request, PosCheckoutService $checkout): JsonResponse
    {
        Gate::authorize('checkout', PosOrder::class);

        $order = $checkout->checkout(
            cashier: $request->user(),
            items: $request->validated('items'),
            payments: $request->validated('payments'),
            customerName: $request->validated('customer_name')
        );

        return PosOrderResource::make($order)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(PosOrder $posOrder): PosOrderResource
    {
        Gate::authorize('view', $posOrder);

        return PosOrderResource::make($posOrder->load(['sale.items.product', 'sale.items.warehouse', 'payments']));
    }
}
