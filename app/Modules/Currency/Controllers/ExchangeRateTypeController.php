<?php

namespace App\Modules\Currency\Controllers;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Currency\Requests\StoreExchangeRateTypeRequest;
use App\Modules\Currency\Requests\UpdateExchangeRateTypeRequest;
use App\Modules\Currency\Resources\ExchangeRateTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ExchangeRateTypeController extends Controller
{
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

        return ExchangeRateTypeResource::make($type);
    }

    public function destroy(ExchangeRateType $type): Response
    {
        Gate::authorize('delete', $type);

        $type->update(['is_active' => false]);

        return response()->noContent();
    }
}
