<?php

namespace App\Modules\Fiscal\Controllers;

use App\Modules\Fiscal\Requests\UpdateBranchFiscalIdentityRequest;
use App\Modules\Fiscal\Requests\UpdateFiscalIdentityRequest;
use App\Modules\Fiscal\Resources\FiscalBranchIdentityResource;
use App\Modules\Fiscal\Resources\FiscalIdentityResource;
use App\Modules\Fiscal\Services\FiscalIdentityService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Routing\Controller;

class FiscalIdentityController extends Controller
{
    public function show(FiscalIdentityService $service): FiscalIdentityResource
    {
        return FiscalIdentityResource::make($service->identity(app(TenantManager::class)->require()));
    }

    public function update(UpdateFiscalIdentityRequest $request, FiscalIdentityService $service): FiscalIdentityResource
    {
        return FiscalIdentityResource::make($service->updateTenant(
            app(TenantManager::class)->require(),
            $request->validated(),
        ));
    }

    public function showBranch(int $branch, FiscalIdentityService $service): FiscalBranchIdentityResource
    {
        return FiscalBranchIdentityResource::make($service->branch(
            app(TenantManager::class)->require(),
            $branch,
        ));
    }

    public function updateBranch(
        UpdateBranchFiscalIdentityRequest $request,
        int $branch,
        FiscalIdentityService $service,
    ): FiscalBranchIdentityResource {
        return FiscalBranchIdentityResource::make($service->updateBranch(
            app(TenantManager::class)->require(),
            $branch,
            $request->validated(),
        ));
    }
}
