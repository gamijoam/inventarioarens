<?php

namespace App\Modules\CashRegister\Controllers;

use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Requests\StoreCashRegisterRequest;
use App\Modules\CashRegister\Requests\UpdateCashRegisterRequest;
use App\Modules\CashRegister\Resources\CashRegisterResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class CashRegisterController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CashRegister::class);

        return CashRegisterResource::collection(
            CashRegister::query()
                ->with(['branch', 'openSession.cashier'])
                ->orderBy('name')
                ->get()
        );
    }

    public function store(StoreCashRegisterRequest $request): JsonResponse
    {
        Gate::authorize('create', CashRegister::class);

        $data = $request->validated();
        $data['code'] = strtoupper($data['code']);

        $cashRegister = CashRegister::create($data)->refresh()->load(['branch', 'openSession.cashier']);

        return CashRegisterResource::make($cashRegister)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(CashRegister $cashRegister, UpdateCashRegisterRequest $request): CashRegisterResource
    {
        Gate::authorize('update', $cashRegister);

        $data = $request->validated();
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        $cashRegister->update($data);

        return CashRegisterResource::make($cashRegister->refresh()->load(['branch', 'openSession.cashier']));
    }
}
