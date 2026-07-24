<?php

namespace App\Modules\Currency\Controllers;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Currency\Requests\StoreExchangeRateTypeRequest;
use App\Modules\Currency\Requests\UpdateExchangeRateTypeRequest;
use App\Modules\Currency\Resources\ExchangeRateTypeResource;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Sync\Services\SyncOutboxService;
use App\Support\Tenancy\Concerns\SharedCatalogWriteGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ExchangeRateTypeController extends Controller
{
    use SharedCatalogWriteGuard;

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

        if (! $this->canWriteSharedCatalog($request->user())) {
            abort(Response::HTTP_FORBIDDEN, 'El catalogo compartido solo lo edita el Owner del grupo.');
        }

        $type = DB::transaction(function () use ($request): ExchangeRateType {
            $data = $request->validated();

            if (($data['is_default'] ?? false) === true) {
                ExchangeRateType::query()->update(['is_default' => false]);
            }

            $created = ExchangeRateType::create($data)->refresh();
            $this->recordSyncEvent('exchange_rate_type.created', $created);

            return $created;
        });

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

        if (! $this->canWriteSharedCatalog($request->user())) {
            abort(Response::HTTP_FORBIDDEN, 'El catalogo compartido solo lo edita el Owner del grupo.');
        }

        $type = DB::transaction(function () use ($request, $type): ExchangeRateType {
            $data = $request->validated();

            if (($data['is_default'] ?? false) === true) {
                ExchangeRateType::query()
                    ->whereKeyNot($type->id)
                    ->update(['is_default' => false]);
            }

            $type->update($data);
            $refreshed = $type->refresh();
            $this->recordSyncEvent('exchange_rate_type.updated', $refreshed);

            return $refreshed;
        });

        return ExchangeRateTypeResource::make($type);
    }

    public function destroy(ExchangeRateType $type): Response
    {
        Gate::authorize('delete', $type);

        if (! $this->canWriteSharedCatalog(request()->user())) {
            abort(Response::HTTP_FORBIDDEN, 'El catalogo compartido solo lo edita el Owner del grupo.');
        }

        DB::transaction(function () use ($type): void {
            $type->update(['is_active' => false]);
            $this->recordSyncEvent('exchange_rate_type.updated', $type->refresh());
        });

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
            idempotencyKey: SyncCatalogOutboxService::eventKey($eventType, 'exchange_rate_type', $type->id, $type->updated_at),
        );
    }
}
