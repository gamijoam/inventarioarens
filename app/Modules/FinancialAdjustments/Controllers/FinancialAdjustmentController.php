<?php

namespace App\Modules\FinancialAdjustments\Controllers;

use App\Modules\FinancialAdjustments\Models\FinancialAdjustment;
use App\Modules\FinancialAdjustments\Requests\StoreFinancialAdjustmentRequest;
use App\Modules\FinancialAdjustments\Resources\FinancialAdjustmentResource;
use App\Modules\FinancialAdjustments\Services\FinancialAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class FinancialAdjustmentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', FinancialAdjustment::class);

        return FinancialAdjustmentResource::collection(
            FinancialAdjustment::query()
                ->latest('applied_at')
                ->paginate(25)
        );
    }

    public function store(
        StoreFinancialAdjustmentRequest $request,
        FinancialAdjustmentService $service,
    ): JsonResponse {
        Gate::authorize('create', FinancialAdjustment::class);

        return FinancialAdjustmentResource::make(
            $service->create($request->user(), $request->validated())
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(FinancialAdjustment $financialAdjustment): FinancialAdjustmentResource
    {
        Gate::authorize('view', $financialAdjustment);

        return FinancialAdjustmentResource::make($financialAdjustment);
    }
}
