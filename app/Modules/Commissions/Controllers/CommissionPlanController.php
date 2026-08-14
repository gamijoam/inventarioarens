<?php

namespace App\Modules\Commissions\Controllers;

use App\Modules\Commissions\Models\CommissionPlan;
use App\Modules\Commissions\Requests\StoreCommissionPlanRequest;
use App\Modules\Commissions\Requests\UpdateCommissionPlanRequest;
use App\Modules\Commissions\Resources\CommissionPlanResource;
use App\Modules\Commissions\Services\CommissionPlanService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class CommissionPlanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('commissions.view_all'), Response::HTTP_FORBIDDEN);

        return CommissionPlanResource::collection(
            CommissionPlan::query()->with(['exchangeRateType', 'assignments.user'])->latest('id')->get()
        );
    }

    public function show(Request $request, CommissionPlan $commissionPlan): CommissionPlanResource
    {
        abort_unless($request->user()?->can('commissions.view_all'), Response::HTTP_FORBIDDEN);
        $this->assertCurrentTenant($commissionPlan);

        return CommissionPlanResource::make($commissionPlan->load(['exchangeRateType', 'assignments.user']));
    }

    public function store(StoreCommissionPlanRequest $request, CommissionPlanService $service): CommissionPlanResource
    {
        abort_unless($request->user()?->can('commissions.manage'), Response::HTTP_FORBIDDEN);

        return CommissionPlanResource::make($service->create($request->validated()));
    }

    public function update(UpdateCommissionPlanRequest $request, CommissionPlan $commissionPlan, CommissionPlanService $service): CommissionPlanResource
    {
        abort_unless($request->user()?->can('commissions.manage'), Response::HTTP_FORBIDDEN);
        $this->assertCurrentTenant($commissionPlan);

        return CommissionPlanResource::make($service->update($commissionPlan, $request->validated()));
    }

    public function destroy(Request $request, CommissionPlan $commissionPlan, CommissionPlanService $service): Response
    {
        abort_unless($request->user()?->can('commissions.manage'), Response::HTTP_FORBIDDEN);
        $this->assertCurrentTenant($commissionPlan);
        $service->deactivate($commissionPlan);

        return response()->noContent();
    }

    private function assertCurrentTenant(CommissionPlan $plan): void
    {
        abort_unless(
            (int) $plan->tenant_id === (int) app(TenantManager::class)->require()->id,
            Response::HTTP_NOT_FOUND
        );
    }
}
