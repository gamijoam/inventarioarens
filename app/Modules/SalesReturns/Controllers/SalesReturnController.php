<?php

namespace App\Modules\SalesReturns\Controllers;

use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\SalesReturns\Requests\CancelSalesReturnRequest;
use App\Modules\SalesReturns\Requests\ProcessSalesReturnRequest;
use App\Modules\SalesReturns\Requests\RejectSalesReturnRequest;
use App\Modules\SalesReturns\Requests\StoreSalesReturnRequest;
use App\Modules\SalesReturns\Resources\SalesReturnResource;
use App\Modules\SalesReturns\Services\SalesReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class SalesReturnController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', SalesReturn::class);

        return SalesReturnResource::collection(
            SalesReturn::query()
                ->with(['sale.customer', 'sale.receivable', 'items.saleItem', 'items.product', 'items.warehouse', 'creator', 'reviewer', 'processor', 'canceller'])
                ->latest()
                ->paginate(25)
        );
    }

    public function store(StoreSalesReturnRequest $request, SalesReturnService $returns): JsonResponse
    {
        Gate::authorize('create', SalesReturn::class);

        $salesReturn = $returns->create($request->user(), $request->validated());

        return SalesReturnResource::make($salesReturn)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(SalesReturn $salesReturn): SalesReturnResource
    {
        Gate::authorize('view', $salesReturn);

        return SalesReturnResource::make(app(SalesReturnService::class)->loadReturn($salesReturn));
    }

    public function approve(SalesReturn $salesReturn, SalesReturnService $returns): SalesReturnResource
    {
        Gate::authorize('review', $salesReturn);

        return SalesReturnResource::make($returns->approve($salesReturn, request()->user()));
    }

    public function reject(RejectSalesReturnRequest $request, SalesReturn $salesReturn, SalesReturnService $returns): SalesReturnResource
    {
        Gate::authorize('review', $salesReturn);

        return SalesReturnResource::make($returns->reject($salesReturn, $request->user(), $request->validated('reason')));
    }

    public function process(ProcessSalesReturnRequest $request, SalesReturn $salesReturn, SalesReturnService $returns): SalesReturnResource
    {
        Gate::authorize('process', $salesReturn);

        $data = $request->validated();
        $refundMode = $data['refund_mode'] ?? 'none';

        if ($refundMode !== 'none') {
            Gate::authorize('refund', $salesReturn);
        }

        if ($refundMode === 'cash' && ! $request->user()->can('cash_register.move')) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return SalesReturnResource::make($returns->process($salesReturn, $request->user(), $data));
    }

    public function cancel(CancelSalesReturnRequest $request, SalesReturn $salesReturn, SalesReturnService $returns): SalesReturnResource
    {
        Gate::authorize('cancel', $salesReturn);

        return SalesReturnResource::make($returns->cancel($salesReturn, $request->user(), $request->validated('reason')));
    }
}
