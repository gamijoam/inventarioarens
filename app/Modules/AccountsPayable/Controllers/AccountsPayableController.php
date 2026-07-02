<?php

namespace App\Modules\AccountsPayable\Controllers;

use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Requests\RegisterAccountsPayablePaymentRequest;
use App\Modules\AccountsPayable\Resources\AccountsPayablePaymentResource;
use App\Modules\AccountsPayable\Resources\AccountsPayableResource;
use App\Modules\AccountsPayable\Services\AccountsPayableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class AccountsPayableController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AccountsPayable::class);

        return AccountsPayableResource::collection(
            AccountsPayable::query()
                ->with(['supplier', 'purchaseOrder'])
                ->latest()
                ->paginate(25)
        );
    }

    public function show(AccountsPayable $accountsPayable): AccountsPayableResource
    {
        Gate::authorize('view', $accountsPayable);

        return AccountsPayableResource::make(
            $accountsPayable->load(['supplier', 'purchaseOrder.items.product', 'payments'])
        );
    }

    public function pay(
        RegisterAccountsPayablePaymentRequest $request,
        AccountsPayable $accountsPayable,
        AccountsPayableService $service,
    ): JsonResponse {
        Gate::authorize('pay', $accountsPayable);

        $payment = $service->registerPayment($accountsPayable, $request->user(), $request->validated());

        return AccountsPayablePaymentResource::make($payment)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
