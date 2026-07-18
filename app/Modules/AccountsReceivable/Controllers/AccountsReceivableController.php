<?php

namespace App\Modules\AccountsReceivable\Controllers;

use App\Modules\AccessControl\Services\ScopeResolver;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Requests\RegisterAccountsReceivablePaymentRequest;
use App\Modules\AccountsReceivable\Resources\AccountsReceivablePaymentResource;
use App\Modules\AccountsReceivable\Resources\AccountsReceivableResource;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AccountsReceivableController extends Controller
{
    public function __construct(private readonly ScopeResolver $scopes) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AccountsReceivable::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([
                'all',
                'open',
                AccountsReceivable::STATUS_PENDING,
                AccountsReceivable::STATUS_PARTIAL,
                AccountsReceivable::STATUS_PAID,
                AccountsReceivable::STATUS_OVERDUE,
            ])],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($filters['limit'] ?? 25);
        $search = trim((string) ($filters['search'] ?? ''));

        $query = AccountsReceivable::query()
            ->with(['customer', 'sale'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($innerQuery) use ($search): void {
                    $innerQuery
                        ->where('document_number', 'ilike', "%{$search}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($search): void {
                            $customerQuery
                                ->where('name', 'ilike', "%{$search}%")
                                ->orWhere('document_number', 'ilike', "%{$search}%");
                        })
                        ->orWhereHas('sale', function ($saleQuery) use ($search): void {
                            $saleQuery->where('document_number', 'ilike', "%{$search}%");
                        });
                });
            })
            ->when(($filters['status'] ?? 'all') === 'open', fn ($query) => $query->whereIn('status', [
                AccountsReceivable::STATUS_PENDING,
                AccountsReceivable::STATUS_PARTIAL,
                AccountsReceivable::STATUS_OVERDUE,
            ]))
            ->when(
                ! in_array(($filters['status'] ?? 'all'), ['all', 'open'], true),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['due_from'] ?? null, fn ($query, $date) => $query->whereDate('due_date', '>=', $date))
            ->when($filters['due_to'] ?? null, fn ($query, $date) => $query->whereDate('due_date', '<=', $date))
            ->latest();

        // Scope por customer_group: filtrar CxC cuyos clientes pertenezcan a los grupos del user.
        $groupIds = $this->scopes->customerGroupIdsFor($request->user());
        if ($groupIds !== null) {
            $query->whereIn('customer_id', function ($sub) use ($groupIds): void {
                $sub->select('id')->from('customers')
                    ->whereIn('customer_group_id', $groupIds);
            });
        }

        return AccountsReceivableResource::collection($query->paginate($limit));
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
