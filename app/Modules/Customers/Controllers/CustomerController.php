<?php

namespace App\Modules\Customers\Controllers;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Requests\StoreCustomerRequest;
use App\Modules\Customers\Requests\UpdateCustomerRequest;
use App\Modules\Customers\Resources\CustomerResource;
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

        return CustomerResource::collection(
            Customer::query()
                ->when($activeOnly, fn ($query) => $query->where('is_active', true))
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

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        Gate::authorize('create', Customer::class);

        $customer = Customer::create($request->validated())->refresh();

        return CustomerResource::make($customer)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Customer $customer): CustomerResource
    {
        Gate::authorize('view', $customer);

        return CustomerResource::make($customer);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        Gate::authorize('update', $customer);

        $customer->update($request->validated());

        return CustomerResource::make($customer->refresh());
    }

    public function destroy(Customer $customer): Response
    {
        Gate::authorize('delete', $customer);

        $customer->update(['is_active' => false]);

        return response()->noContent();
    }
}
