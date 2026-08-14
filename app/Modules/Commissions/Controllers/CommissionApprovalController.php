<?php

namespace App\Modules\Commissions\Controllers;

use App\Modules\Commissions\Requests\ApproveCommissionsRequest;
use App\Modules\Commissions\Resources\CommissionEntryResource;
use App\Modules\Commissions\Services\CommissionSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class CommissionApprovalController extends Controller
{
    public function __invoke(ApproveCommissionsRequest $request, CommissionSettlementService $service): JsonResponse
    {
        $entries = $service->approve($request->validated('entry_ids'), $request->user());

        return response()->json([
            'data' => CommissionEntryResource::collection($entries)->resolve($request),
        ]);
    }
}
