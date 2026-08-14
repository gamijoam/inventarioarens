<?php

namespace App\Modules\Commissions\Controllers;

use App\Modules\Commissions\Requests\SimulateCommissionRequest;
use App\Modules\Commissions\Services\CommissionPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class CommissionSimulatorController extends Controller
{
    public function __invoke(SimulateCommissionRequest $request, CommissionPlanService $service): JsonResponse
    {
        abort_unless($request->user()?->can('commissions.manage'), Response::HTTP_FORBIDDEN);
        $data = $request->validated();

        return response()->json(['data' => $service->simulate(
            (float) $data['amount'],
            $data['currency'],
            (float) $data['percentage'],
            $data['exchange_rate_type_id'] ?? null,
        )]);
    }
}
