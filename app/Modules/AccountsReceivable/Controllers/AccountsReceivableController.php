<?php

namespace App\Modules\AccountsReceivable\Controllers;

use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Requests\RegisterAccountsReceivablePaymentRequest;
use App\Modules\AccountsReceivable\Resources\AccountsReceivablePaymentResource;
use App\Modules\AccountsReceivable\Resources\AccountsReceivableResource;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class AccountsReceivableController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AccountsReceivable::class);

        return AccountsReceivableResource::collection(
            AccountsReceivable::query()
                ->with(['customer', 'sale'])
                ->latest()
                ->paginate(25)
        );
    }

    public function show(AccountsReceivable $accountsReceivable): AccountsReceivableResource
    {
        Gate::authorize('view', $accountsReceivable);

        return AccountsReceivableResource::make(
            $accountsReceivable->load(['customer', 'sale.items.product', 'payments'])
        );
    }

    public function collect(
        RegisterAccountsReceivablePaymentRequest $request,
        AccountsReceivable $accountsReceivable,
        AccountsReceivableService $service,
    ): JsonResponse {
        Gate::authorize('collect', $accountsReceivable);

        $payment = $service->registerPayment($accountsReceivable, $request->user(), $request->validated());

        return AccountsReceivablePaymentResource::make($payment)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
