<?php

namespace App\Modules\Commissions\Controllers;

use App\Modules\Commissions\Models\CommissionSettlement;
use App\Modules\Commissions\Requests\StoreCommissionSettlementRequest;
use App\Modules\Commissions\Resources\CommissionSettlementResource;
use App\Modules\Commissions\Services\CommissionSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class CommissionSettlementController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('commissions.view_all'), Response::HTTP_FORBIDDEN);

        return CommissionSettlementResource::collection(
            CommissionSettlement::query()->with(['beneficiary', 'items.entry'])->latest('paid_at')->paginate(50)
        );
    }

    public function store(StoreCommissionSettlementRequest $request, CommissionSettlementService $service): JsonResponse
    {
        return CommissionSettlementResource::make($service->settle($request->validated(), $request->user()))
            ->response()
            ->setStatusCode(201);
    }
}
