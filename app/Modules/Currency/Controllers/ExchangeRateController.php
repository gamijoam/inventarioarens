<?php

namespace App\Modules\Currency\Controllers;

use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Requests\StoreExchangeRateRequest;
use App\Modules\Currency\Resources\ExchangeRateResource;
use App\Modules\Currency\Services\ExchangeRateActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class ExchangeRateController extends Controller
{
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

        $rate = ExchangeRate::create($request->validated())->refresh()->load('type');

        if ($rate->is_active) {
            $rate = $activationService->activate($rate);
        }

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

        return ExchangeRateResource::make($activationService->activate($rate));
    }
}
