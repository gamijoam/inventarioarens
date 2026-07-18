<?php

namespace App\Modules\AccountsPayable\Controllers;

use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Requests\RegisterAccountsPayablePaymentRequest;
use App\Modules\AccountsPayable\Requests\StoreAccountsPayablePaymentRequestRequest;
use App\Modules\AccountsPayable\Resources\AccountsPayablePaymentRequestResource;
use App\Modules\AccountsPayable\Resources\AccountsPayablePaymentResource;
use App\Modules\AccountsPayable\Resources\AccountsPayableResource;
use App\Modules\AccountsPayable\Services\AccountsPayablePaymentRequestService;
use App\Modules\AccountsPayable\Services\AccountsPayableService;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AccountsPayableController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AccountsPayable::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([
                'all',
                'open',
                AccountsPayable::STATUS_PENDING,
                AccountsPayable::STATUS_PARTIAL,
                AccountsPayable::STATUS_PAID,
                AccountsPayable::STATUS_OVERDUE,
            ])],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($filters['limit'] ?? 25);
        $search = trim((string) ($filters['search'] ?? ''));

        return AccountsPayableResource::collection(
            AccountsPayable::query()
                ->with(['supplier', 'purchaseOrder', 'paymentRequests'])
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($innerQuery) use ($search): void {
                        $innerQuery
                            ->where('document_number', 'like', "%{$search}%")
                            ->orWhereHas('supplier', function ($supplierQuery) use ($search): void {
                                $supplierQuery
                                    ->where('name', 'like', "%{$search}%")
                                    ->orWhere('document_number', 'like', "%{$search}%");
                            })
                            ->orWhereHas('purchaseOrder', function ($purchaseQuery) use ($search): void {
                                $purchaseQuery->where('document_number', 'like', "%{$search}%");
                            });
                    });
                })
                ->when(($filters['status'] ?? 'all') === 'open', fn ($query) => $query->whereIn('status', [
                    AccountsPayable::STATUS_PENDING,
                    AccountsPayable::STATUS_PARTIAL,
                    AccountsPayable::STATUS_OVERDUE,
                ]))
                ->when(! in_array(($filters['status'] ?? 'all'), ['all', 'open'], true), fn ($query) => $query->where('status', $filters['status']))
                ->when($filters['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
                ->when($filters['due_from'] ?? null, fn ($query, $date) => $query->whereDate('due_date', '>=', $date))
                ->when($filters['due_to'] ?? null, fn ($query, $date) => $query->whereDate('due_date', '<=', $date))
                ->latest()
                ->paginate($limit)
        );
    }

    public function show(AccountsPayable $accountsPayable): AccountsPayableResource
    {
        Gate::authorize('view', $accountsPayable);

        return AccountsPayableResource::make(
            $accountsPayable->load(['supplier', 'purchaseOrder.items.product', 'payments', 'paymentRequests.payment'])
        );
    }

    public function preparePaymentRequest(
        StoreAccountsPayablePaymentRequestRequest $request,
        AccountsPayable $accountsPayable,
        AccountsPayablePaymentRequestService $service,
    ): JsonResponse {
        Gate::authorize('view', $accountsPayable);

        if (! $request->user()->can('accounts_payable.payment_requests.prepare')) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return AccountsPayablePaymentRequestResource::make(
            $service->prepare($accountsPayable, $request->user(), $request->validated())
        )
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function pay(
        RegisterAccountsPayablePaymentRequest $request,
        AccountsPayable $accountsPayable,
        AccountsPayableService $service,
    ): JsonResponse {
        Gate::authorize('pay', $accountsPayable);

        $data = $request->validated();

        if (($data['method'] ?? null) === CashRegisterMovement::METHOD_CASH && ! empty($data['cash_register_session_id'])) {
            Gate::authorize('move', CashRegisterSession::query()->findOrFail($data['cash_register_session_id']));
        }

        $payment = $service->registerPayment($accountsPayable, $request->user(), $data);

        return AccountsPayablePaymentResource::make($payment)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
