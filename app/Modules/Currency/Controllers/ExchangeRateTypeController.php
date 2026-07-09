<?php

namespace App\Modules\Currency\Controllers;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Currency\Requests\StoreExchangeRateTypeRequest;
use App\Modules\Currency\Requests\UpdateExchangeRateTypeRequest;
use App\Modules\Currency\Resources\ExchangeRateTypeResource;
use App\Modules\Sync\Services\SyncOutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ExchangeRateTypeController extends Controller
{
    public function __construct(private readonly SyncOutboxService $syncOutbox) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ExchangeRateType::class);

        return ExchangeRateTypeResource::collection(
            ExchangeRateType::query()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->paginate(25)
        );
    }

    public function store(StoreExchangeRateTypeRequest $request): JsonResponse
    {
        Gate::authorize('create', ExchangeRateType::class);

        $type = DB::transaction(function () use ($request): ExchangeRateType {
            $data = $request->validated();

            if (($data['is_default'] ?? false) === true) {
                ExchangeRateType::query()->update(['is_default' => false]);
            }

            return ExchangeRateType::create($data)->refresh();
        });

        $this->recordSyncEvent('exchange_rate_type.created', $type);

        return ExchangeRateTypeResource::make($type)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ExchangeRateType $type): ExchangeRateTypeResource
    {
        Gate::authorize('view', $type);

        return ExchangeRateTypeResource::make($type);
    }

    public function update(UpdateExchangeRateTypeRequest $request, ExchangeRateType $type): ExchangeRateTypeResource
    {
        Gate::authorize('update', $type);

        $type = DB::transaction(function () use ($request, $type): ExchangeRateType {
            $data = $request->validated();

            if (($data['is_default'] ?? false) === true) {
                ExchangeRateType::query()
                    ->whereKeyNot($type->id)
                    ->update(['is_default' => false]);
            }

            $type->update($data);

            return $type->refresh();
        });

        $this->recordSyncEvent('exchange_rate_type.updated', $type);

        return ExchangeRateTypeResource::make($type);
    }

    public function destroy(ExchangeRateType $type): Response
    {
        Gate::authorize('delete', $type);

        $type->update(['is_active' => false]);
        $this->recordSyncEvent('exchange_rate_type.updated', $type->refresh());

        return response()->noContent();
    }

    private function recordSyncEvent(string $eventType, ExchangeRateType $type): void
    {
        $this->syncOutbox->record(
            eventType: $eventType,
            aggregateType: 'exchange_rate_type',
            aggregateId: $type->id,
            payload: [
                'code' => $type->code,
                'name' => $type->name,
                'is_default' => (bool) $type->is_default,
                'is_active' => (bool) $type->is_active,
            ],
            idempotencyKey: sprintf('currency:%s:%s:%s', $eventType, $type->id, Str::uuid()),
        );
    }
}
