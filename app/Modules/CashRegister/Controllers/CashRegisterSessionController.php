<?php

namespace App\Modules\CashRegister\Controllers;

use App\Models\User;
use App\Modules\AccessControl\Services\ScopeResolver;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Requests\CloseCashRegisterSessionRequest;
use App\Modules\CashRegister\Requests\OpenCashRegisterSessionRequest;
use App\Modules\CashRegister\Requests\StoreCashRegisterMovementRequest;
use App\Modules\CashRegister\Resources\CashRegisterSessionResource;
use App\Modules\CashRegister\Services\CashRegisterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class CashRegisterSessionController extends Controller
{
    public function __construct(private readonly ScopeResolver $scopes) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CashRegisterSession::class);

        $query = CashRegisterSession::query()
            ->with(['branch', 'cashRegister', 'cashier', 'closer', 'movements'])
            ->latest();

        $status = $request->query('status');
        if (in_array($status, [
            CashRegisterSession::STATUS_OPEN,
            CashRegisterSession::STATUS_CLOSED,
            CashRegisterSession::STATUS_CANCELLED,
        ], true)) {
            $query->where('status', $status);
        }

        if ($request->query('cashier_id') === 'me') {
            $query->where('cashier_id', $request->user()->id);
        } elseif ($request->filled('cashier_id')) {
            $query->where('cashier_id', (int) $request->query('cashier_id'));
        }

        if ($request->filled('cash_register_id')) {
            $query->where('cash_register_id', (int) $request->query('cash_register_id'));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->query('branch_id'));
        }

        $query = $this->scopes->applyBranchScope($query, $request->user(), 'branch_id');

        return CashRegisterSessionResource::collection(
            $query->paginate((int) $request->query('per_page', 25))
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

        return CashRegisterSessionResource::make($cashRegisterSession->load(['branch', 'cashRegister', 'cashier', 'closer', 'movements']));
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
