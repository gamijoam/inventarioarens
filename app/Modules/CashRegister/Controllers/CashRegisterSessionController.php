<?php

namespace App\Modules\CashRegister\Controllers;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Requests\CloseCashRegisterSessionRequest;
use App\Modules\CashRegister\Requests\OpenCashRegisterSessionRequest;
use App\Modules\CashRegister\Requests\StoreCashRegisterMovementRequest;
use App\Modules\CashRegister\Resources\CashRegisterSessionResource;
use App\Modules\CashRegister\Services\CashRegisterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class CashRegisterSessionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CashRegisterSession::class);

        return CashRegisterSessionResource::collection(
            CashRegisterSession::query()
                ->with(['branch', 'cashRegister'])
                ->latest()
                ->paginate(25)
        );
    }

    public function open(OpenCashRegisterSessionRequest $request, CashRegisterService $cashRegister): JsonResponse
    {
        Gate::authorize('open', CashRegisterSession::class);

        $data = $request->validated();
        $session = $cashRegister->open(
            operator: $request->user(),
            branch: Branch::query()->findOrFail($data['branch_id']),
            physicalRegister: isset($data['cash_register_id']) ? CashRegister::query()->findOrFail($data['cash_register_id']) : null,
            cashier: isset($data['cashier_id']) ? User::query()->findOrFail($data['cashier_id']) : null,
            data: $data,
        );

        return CashRegisterSessionResource::make($session)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(CashRegisterSession $cashRegisterSession): CashRegisterSessionResource
    {
        Gate::authorize('view', $cashRegisterSession);

        return CashRegisterSessionResource::make($cashRegisterSession->load(['branch', 'cashRegister', 'movements']));
    }

    public function movement(
        CashRegisterSession $cashRegisterSession,
        StoreCashRegisterMovementRequest $request,
        CashRegisterService $cashRegister,
    ): CashRegisterSessionResource {
        Gate::authorize('move', $cashRegisterSession);

        return CashRegisterSessionResource::make(
            $cashRegister->addMovement($cashRegisterSession, $request->validated(), $request->user())
        );
    }

    public function close(
        CashRegisterSession $cashRegisterSession,
        CloseCashRegisterSessionRequest $request,
        CashRegisterService $cashRegister,
    ): CashRegisterSessionResource {
        Gate::authorize('close', $cashRegisterSession);

        return CashRegisterSessionResource::make(
            $cashRegister->close($cashRegisterSession, $request->validated(), $request->user())
        );
    }
}
