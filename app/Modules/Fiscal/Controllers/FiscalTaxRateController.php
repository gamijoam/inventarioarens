<?php

namespace App\Modules\Fiscal\Controllers;

use App\Modules\Fiscal\Requests\StoreFiscalTaxRateRequest;
use App\Modules\Fiscal\Requests\UpdateFiscalTaxRateRequest;
use App\Modules\Fiscal\Resources\FiscalTaxRateResource;
use App\Modules\Fiscal\Services\FiscalTaxRateService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class FiscalTaxRateController extends Controller
{
    public function index(FiscalTaxRateService $service): AnonymousResourceCollection
    {
        return FiscalTaxRateResource::collection($service->list(app(TenantManager::class)->require()));
    }

    public function store(StoreFiscalTaxRateRequest $request, FiscalTaxRateService $service): JsonResponse
    {
        return FiscalTaxRateResource::make($service->create($request->validated()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(int $taxRate, FiscalTaxRateService $service): FiscalTaxRateResource
    {
        return FiscalTaxRateResource::make($service->find(
            app(TenantManager::class)->require(),
            $taxRate,
        ));
    }

    public function update(UpdateFiscalTaxRateRequest $request, int $taxRate, FiscalTaxRateService $service): FiscalTaxRateResource
    {
        $tenant = app(TenantManager::class)->require();

        return FiscalTaxRateResource::make($service->update(
            $service->find($tenant, $taxRate),
            $request->validated(),
        ));
    }
}
