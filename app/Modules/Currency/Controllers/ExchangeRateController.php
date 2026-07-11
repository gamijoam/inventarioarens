<?php

namespace App\Modules\Currency\Controllers;

use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Requests\StoreExchangeRateRequest;
use App\Modules\Currency\Resources\ExchangeRateResource;
use App\Modules\Currency\Services\ExchangeRateActivationService;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Sync\Services\SyncOutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ExchangeRateController extends Controller
{
    public function __construct(private readonly SyncOutboxService $syncOutbox) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ExchangeRate::class);

        return ExchangeRateResource::collection(
            ExchangeRate::query()
                ->with('type')
                ->latest('effective_at')
                ->paginate(25)
        );
    }

    public function current(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ExchangeRate::class);

        $query = ExchangeRate::query()
            ->with('type')
            ->where('is_active', true)
            ->latest('effective_at');

        if ($request->filled('rate_type_code')) {
            $query->whereHas('type', fn ($typeQuery) => $typeQuery->where('code', $request->string('rate_type_code')->toString()));
        }

        return ExchangeRateResource::collection($query->get());
    }

    public function store(StoreExchangeRateRequest $request, ExchangeRateActivationService $activationService): JsonResponse
    {
        Gate::authorize('create', ExchangeRate::class);

        $rate = DB::transaction(function () use ($request, $activationService): ExchangeRate {
            $data = $request->validated();
            $deactivatedRates = collect();

            if (($data['is_active'] ?? false) === true) {
                $deactivatedRates = ExchangeRate::query()
                    ->with('type')
                    ->where('exchange_rate_type_id', $data['exchange_rate_type_id'])
                    ->where('base_currency', $data['base_currency'] ?? ExchangeRate::BASE_USD)
                    ->where('quote_currency', $data['quote_currency'] ?? ExchangeRate::QUOTE_VES)
                    ->where('is_active', true)
                    ->get();
            }

            $created = ExchangeRate::create($data)->refresh()->load('type');

            if ($created->is_active) {
                $created = $activationService->activate($created);
            }

            $deactivatedRates
                ->where('id', '!=', $created->id)
                ->each(fn (ExchangeRate $deactivatedRate) => $this->recordSyncEvent('exchange_rate.updated', $deactivatedRate->refresh()->load('type')));

            $this->recordSyncEvent('exchange_rate.created', $created);

            return $created;
        });

        return ExchangeRateResource::make($rate)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ExchangeRate $rate): ExchangeRateResource
    {
        Gate::authorize('view', $rate);

        return ExchangeRateResource::make($rate->load('type'));
    }

    public function activate(ExchangeRate $rate, ExchangeRateActivationService $activationService): ExchangeRateResource
    {
        Gate::authorize('update', $rate);

        $rate = DB::transaction(function () use ($rate, $activationService): ExchangeRate {
            $deactivatedRates = ExchangeRate::query()
                ->with('type')
                ->where('exchange_rate_type_id', $rate->exchange_rate_type_id)
                ->where('base_currency', $rate->base_currency)
                ->where('quote_currency', $rate->quote_currency)
                ->where('is_active', true)
                ->whereKeyNot($rate->id)
                ->get();

            $activated = $activationService->activate($rate);

            $deactivatedRates->each(fn (ExchangeRate $deactivatedRate) => $this->recordSyncEvent('exchange_rate.updated', $deactivatedRate->refresh()->load('type')));
            $this->recordSyncEvent('exchange_rate.updated', $activated);

            return $activated;
        });

        return ExchangeRateResource::make($rate);
    }

    public function deactivate(ExchangeRate $rate): ExchangeRateResource
    {
        Gate::authorize('update', $rate);

        $rate = DB::transaction(function () use ($rate): ExchangeRate {
            $rate->update(['is_active' => false]);
            $refreshed = $rate->refresh()->load('type');
            $this->recordSyncEvent('exchange_rate.updated', $refreshed);

            return $refreshed;
        });

        return ExchangeRateResource::make($rate);
    }

    private function recordSyncEvent(string $eventType, ExchangeRate $rate): void
    {
        $this->syncOutbox->record(
            eventType: $eventType,
            aggregateType: 'exchange_rate',
            aggregateId: $rate->id,
            payload: [
                'exchange_rate_type_code' => $rate->type?->code,
                'base_currency' => $rate->base_currency,
                'quote_currency' => $rate->quote_currency,
                'rate' => (string) $rate->rate,
                'effective_at' => $rate->effective_at?->toISOString(),
                'source' => $rate->source,
                'is_active' => (bool) $rate->is_active,
            ],
            idempotencyKey: SyncCatalogOutboxService::eventKey($eventType, 'exchange_rate', $rate->id, $rate->updated_at),
        );
    }
}
