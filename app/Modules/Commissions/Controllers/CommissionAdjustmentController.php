<?php

namespace App\Modules\Commissions\Controllers;

use App\Modules\Commissions\Requests\StoreCommissionAdjustmentRequest;
use App\Modules\Commissions\Resources\CommissionEntryResource;
use App\Modules\Commissions\Services\CommissionSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class CommissionAdjustmentController extends Controller
{
    public function __invoke(StoreCommissionAdjustmentRequest $request, CommissionSettlementService $service): JsonResponse
    {
        return CommissionEntryResource::make($service->adjust($request->validated(), $request->user()))
            ->response()
            ->setStatusCode(201);
    }
}
