<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Requests\CaptureStockCountRequest;
use App\Modules\Inventory\Requests\StoreStockCountRequest;
use App\Modules\Inventory\Requests\UpdateStockCountRequest;
use App\Modules\Inventory\Resources\StockCountResource;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class StockCountController extends Controller
{
    public function __construct(private readonly StockCountService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StockCount::query()
            ->with(['warehouse'])
            ->withCount('items')
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('count_type'), fn ($q) => $q->where('count_type', $request->string('count_type')))
            ->latest('id');

        return StockCountResource::collection($query->paginate(25));
    }

    public function store(StoreStockCountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $tenant = app(TenantManager::class)->require();

        $count = $this->service->create($tenant, $warehouse, $data, $request->user()?->id);

        return StockCountResource::make($count->load(['warehouse']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(StockCount $stockCount): StockCountResource
    {
        return StockCountResource::make(
            $stockCount->load(['warehouse', 'items.product', 'items.location'])
        );
    }

    public function update(UpdateStockCountRequest $request, StockCount $stockCount): StockCountResource
    {
        if ($stockCount->status !== StockCount::STATUS_DRAFT) {
            abort(422, 'Solo se puede editar un conteo en estado draft.');
        }

        $stockCount->fill($request->validated())->save();

        return StockCountResource::make($stockCount->refresh());
    }

    public function destroy(StockCount $stockCount): Response
    {
        $this->service->cancel($stockCount, null);

        return response()->noContent();
    }

    public function snapshot(StockCount $stockCount): JsonResponse
    {
        if ($stockCount->status !== StockCount::STATUS_DRAFT) {
            abort(422, 'Solo se puede hacer snapshot en estado draft.');
        }

        $count = $this->service->snapshot($stockCount);

        return response()->json(['data' => ['items_created' => $count]]);
    }

    public function start(StockCount $stockCount): StockCountResource
    {
        $this->service->start($stockCount);

        return StockCountResource::make(
            $stockCount->refresh()->load(['warehouse', 'items.product', 'items.location'])
        );
    }

    public function capture(CaptureStockCountRequest $request, StockCount $stockCount): JsonResponse
    {
        $payload = $request->validated('captures');
        $items = [];
        foreach ($payload as $c) {
            $items[(int) $c['item_id']] = (float) $c['counted_quantity'];
        }
        $count = $this->service->capture($stockCount, $items, $request->user()?->id);

        return response()->json(['data' => ['items_captured' => $count]]);
    }

    public function complete(Request $request, StockCount $stockCount): JsonResponse
    {
        $result = $this->service->complete($stockCount, $request->user()?->id);

        return response()->json([
            'data' => [
                'adjustments' => $result,
                'completed_at' => $stockCount->refresh()->completed_at?->toIso8601String(),
            ],
        ]);
    }
}
