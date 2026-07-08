<?php

namespace App\Modules\Customers\Controllers;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Requests\StoreCustomerRequest;
use App\Modules\Customers\Requests\UpdateCustomerRequest;
use App\Modules\Customers\Resources\CustomerResource;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Customer::class);

        $search = trim((string) $request->query('search', ''));
        $limit = min(max((int) $request->query('limit', 25), 1), 100);
        $activeOnly = $request->boolean('active_only');
        $activeStatus = (string) $request->query('active_status', 'all');

        return CustomerResource::collection(
            Customer::query()
                ->when($activeOnly, fn ($query) => $query->where('is_active', true))
                ->when(! $activeOnly && $activeStatus === 'active', fn ($query) => $query->where('is_active', true))
                ->when(! $activeOnly && $activeStatus === 'inactive', fn ($query) => $query->where('is_active', false))
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $like = "%{$search}%";
                        $query
                            ->where('name', 'ilike', $like)
                            ->orWhere('document_number', 'ilike', $like)
                            ->orWhere('phone', 'ilike', $like)
                            ->orWhere('email', 'ilike', $like);
                    });
                })
                ->orderByDesc('is_generic')
                ->orderBy('name')
                ->paginate($limit)
        );
    }

    public function store(StoreCustomerRequest $request, SyncCatalogOutboxService $syncCatalog): JsonResponse
    {
        Gate::authorize('create', Customer::class);

        $customer = Customer::create($request->validated())->refresh();
        $syncCatalog->customerCreated($customer);

        return CustomerResource::make($customer)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Customer $customer): CustomerResource
    {
        Gate::authorize('view', $customer);

        if (str_contains((string) $request->query('include', ''), 'pos_history')) {
            $customer->setAttribute('pos_history', $this->posHistory($customer));
        }

        return CustomerResource::make($customer);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, SyncCatalogOutboxService $syncCatalog): CustomerResource
    {
        Gate::authorize('update', $customer);

        $customer->update($request->validated());
        $customer = $customer->refresh();
        $syncCatalog->customerUpdated($customer);

        return CustomerResource::make($customer);
    }

    public function destroy(Customer $customer, SyncCatalogOutboxService $syncCatalog): Response
    {
        Gate::authorize('delete', $customer);

        $customer->update(['is_active' => false]);
        $syncCatalog->customerDeactivated($customer->refresh());

        return response()->noContent();
    }

    private function posHistory(Customer $customer): array
    {
        $baseQuery = PosOrder::query()
            ->where('tenant_id', $customer->tenant_id)
            ->where('customer_id', $customer->id);

        $totalOrders = (clone $baseQuery)->count();
        $paidOrders = (clone $baseQuery)->where('status', PosOrder::STATUS_PAID)->count();
        $openOrders = (clone $baseQuery)->where('status', PosOrder::STATUS_OPEN)->count();
        $totalBaseAmount = (float) (clone $baseQuery)->sum('total_base_amount');
        $paidBaseAmount = (float) (clone $baseQuery)->sum('paid_base_amount');
        $lastOrder = (clone $baseQuery)
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->first();

        $recentOrders = (clone $baseQuery)
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (PosOrder $order): array => [
                'id' => $order->id,
                'status' => $order->status,
                'status_label' => $this->statusLabel($order->status),
                'total_base_amount' => round((float) $order->total_base_amount, 4),
                'paid_base_amount' => round((float) $order->paid_base_amount, 4),
                'opened_at' => $order->opened_at?->toISOString(),
                'paid_at' => $order->paid_at?->toISOString(),
            ])
            ->all();

        return [
            'total_orders' => $totalOrders,
            'paid_orders' => $paidOrders,
            'open_orders' => $openOrders,
            'total_base_amount' => round($totalBaseAmount, 4),
            'paid_base_amount' => round($paidBaseAmount, 4),
            'balance_base_amount' => round(max($totalBaseAmount - $paidBaseAmount, 0), 4),
            'last_order_at' => $lastOrder?->opened_at?->toISOString(),
            'recent_orders' => $recentOrders,
        ];
    }

    private function statusLabel(string $status): string
    {
        return [
            PosOrder::STATUS_OPEN => 'Pendiente',
            PosOrder::STATUS_PAID => 'Pagada',
            PosOrder::STATUS_CANCELLED => 'Cancelada',
        ][$status] ?? ucfirst($status);
    }
}
