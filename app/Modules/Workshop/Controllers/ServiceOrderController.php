<?php

namespace App\Modules\Workshop\Controllers;

use App\Modules\Workshop\Models\ServiceOrder;
use App\Modules\Workshop\Models\ServiceOrderPart;
use App\Modules\Workshop\Requests\AddServiceOrderPartRequest;
use App\Modules\Workshop\Requests\AssignTechnicianRequest;
use App\Modules\Workshop\Requests\DiagnoseServiceOrderRequest;
use App\Modules\Workshop\Requests\StoreServiceOrderRequest;
use App\Modules\Workshop\Requests\UpdateServiceOrderRequest;
use App\Modules\Workshop\Resources\ServiceOrderPartResource;
use App\Modules\Workshop\Resources\ServiceOrderResource;
use App\Modules\Workshop\Services\ServiceOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class ServiceOrderController extends Controller
{
    public function __construct(private readonly ServiceOrderService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ServiceOrder::class);

        $filters = $request->validate([
            'status' => ['nullable', 'string'],
            'technician_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        return ServiceOrderResource::collection(
            ServiceOrder::query()
                ->with(['warehouse', 'technician', 'parts'])
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($filters['technician_id'] ?? null, fn ($query, $id) => $query->where('technician_id', (int) $id))
                ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query
                            ->where('order_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%")
                            ->orWhere('device_description', 'like', "%{$search}%");
                    });
                })
                ->latest()
                ->paginate(min(max((int) ($filters['limit'] ?? 25), 1), 100))
        );
    }

    public function store(StoreServiceOrderRequest $request): JsonResponse
    {
        Gate::authorize('create', ServiceOrder::class);

        $order = $this->service->create($request->user(), $request->validated());

        return ServiceOrderResource::make($order->load(['warehouse', 'technician', 'parts']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('view', $serviceOrder);

        return ServiceOrderResource::make(
            $serviceOrder->load(['warehouse', 'technician', 'parts', 'parts.product'])
        );
    }

    public function update(UpdateServiceOrderRequest $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);

        return ServiceOrderResource::make(
            $this->service->update($serviceOrder, $request->user(), $request->validated())
        );
    }

    public function diagnose(DiagnoseServiceOrderRequest $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);

        return ServiceOrderResource::make(
            $this->service->diagnose($serviceOrder, $request->user(), $request->validated())
        );
    }

    public function assignTechnician(AssignTechnicianRequest $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('assignTechnician', $serviceOrder);

        return ServiceOrderResource::make(
            $this->service->assignTechnician($serviceOrder, $request->user(), $request->validated())
        );
    }

    public function addPart(AddServiceOrderPartRequest $request, ServiceOrder $serviceOrder): JsonResponse
    {
        Gate::authorize('update', $serviceOrder);

        $part = $this->service->addPart($serviceOrder, $request->user(), $request->validated());

        return ServiceOrderPartResource::make($part->load(['product', 'warehouse']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function removePart(Request $request, ServiceOrder $serviceOrder, ServiceOrderPart $part): Response
    {
        Gate::authorize('update', $serviceOrder);
        if ($part->service_order_id !== $serviceOrder->id) {
            return response()->json(['message' => 'La pieza no pertenece a la orden.'], 404);
        }

        $this->service->removePart($serviceOrder, $request->user(), $part);

        return response()->noContent();
    }

    public function complete(Request $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('close', $serviceOrder);

        return ServiceOrderResource::make(
            $this->service->complete($serviceOrder, $request->user())
        );
    }

    public function cancel(Request $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('close', $serviceOrder);

        return ServiceOrderResource::make(
            $this->service->cancel($serviceOrder, $request->user())
        );
    }
}
