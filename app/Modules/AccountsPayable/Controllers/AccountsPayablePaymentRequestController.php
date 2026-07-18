<?php

namespace App\Modules\AccountsPayable\Controllers;

use App\Modules\AccountsPayable\Models\AccountsPayablePaymentRequest;
use App\Modules\AccountsPayable\Requests\ExecuteAccountsPayablePaymentRequestRequest;
use App\Modules\AccountsPayable\Requests\RejectAccountsPayablePaymentRequestRequest;
use App\Modules\AccountsPayable\Resources\AccountsPayablePaymentRequestResource;
use App\Modules\AccountsPayable\Services\AccountsPayablePaymentRequestService;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AccountsPayablePaymentRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AccountsPayablePaymentRequest::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([
                'all',
                AccountsPayablePaymentRequest::STATUS_PREPARED,
                AccountsPayablePaymentRequest::STATUS_APPROVED,
                AccountsPayablePaymentRequest::STATUS_REJECTED,
                AccountsPayablePaymentRequest::STATUS_CANCELLED,
                AccountsPayablePaymentRequest::STATUS_EXECUTED,
            ])],
            'accounts_payable_id' => ['nullable', 'integer', 'exists:accounts_payables,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return AccountsPayablePaymentRequestResource::collection(
            AccountsPayablePaymentRequest::query()
                ->with(['account.supplier', 'account.purchaseOrder', 'payment'])
                ->when(($filters['status'] ?? 'all') !== 'all', fn ($query) => $query->where('status', $filters['status']))
                ->when($filters['accounts_payable_id'] ?? null, fn ($query, $accountId) => $query->where('accounts_payable_id', $accountId))
                ->latest()
                ->paginate((int) ($filters['limit'] ?? 25))
        );
    }

    public function approve(
        AccountsPayablePaymentRequest $paymentRequest,
        AccountsPayablePaymentRequestService $service,
    ): AccountsPayablePaymentRequestResource {
        Gate::authorize('approve', $paymentRequest);

        return AccountsPayablePaymentRequestResource::make(
            $service->approve($paymentRequest, request()->user())
        );
    }

    public function reject(
        RejectAccountsPayablePaymentRequestRequest $request,
        AccountsPayablePaymentRequest $paymentRequest,
        AccountsPayablePaymentRequestService $service,
    ): AccountsPayablePaymentRequestResource {
        Gate::authorize('approve', $paymentRequest);

        return AccountsPayablePaymentRequestResource::make(
            $service->reject($paymentRequest, $request->user(), $request->validated('reason'))
        );
    }

    public function cancel(
        RejectAccountsPayablePaymentRequestRequest $request,
        AccountsPayablePaymentRequest $paymentRequest,
        AccountsPayablePaymentRequestService $service,
    ): AccountsPayablePaymentRequestResource {
        Gate::authorize('cancel', $paymentRequest);

        return AccountsPayablePaymentRequestResource::make(
            $service->cancel($paymentRequest, $request->user(), $request->validated('reason'))
        );
    }

    public function execute(
        ExecuteAccountsPayablePaymentRequestRequest $request,
        AccountsPayablePaymentRequest $paymentRequest,
        AccountsPayablePaymentRequestService $service,
    ): JsonResponse {
        Gate::authorize('execute', $paymentRequest);

        $data = $request->validated();

        if ($paymentRequest->method === CashRegisterMovement::METHOD_CASH && ! empty($data['cash_register_session_id'])) {
            Gate::authorize('move', CashRegisterSession::query()->findOrFail($data['cash_register_session_id']));
        }

        return AccountsPayablePaymentRequestResource::make(
            $service->execute($paymentRequest, $request->user(), $data)
        )
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
