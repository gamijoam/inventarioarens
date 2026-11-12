<?php

namespace App\Modules\Suppliers\Controllers;

use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Requests\StoreSupplierCreditRefundRequest;
use App\Modules\Suppliers\Services\SupplierCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class SupplierCreditController extends Controller
{
    public function show(Supplier $supplier, SupplierCreditService $service): JsonResponse
    {
        Gate::authorize('view', $supplier);

        return response()->json(['data' => $service->summary($supplier)]);
    }

    public function refund(StoreSupplierCreditRefundRequest $request, Supplier $supplier, SupplierCreditService $service): JsonResponse
    {
        Gate::authorize('view', $supplier);
        abort_unless($request->user()->can('accounts_payable.pay'), Response::HTTP_FORBIDDEN);

        $data = $request->validated();

        if (($data['method'] ?? null) === CashRegisterMovement::METHOD_CASH && ! empty($data['cash_register_session_id'])) {
            Gate::authorize('move', CashRegisterSession::query()->findOrFail($data['cash_register_session_id']));
        }

        $transaction = $service->recordRefund($supplier, $request->user(), $data);

        return response()->json(['data' => [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'currency' => $transaction->currency,
            'amount' => (float) $transaction->amount,
            'amount_base' => (float) $transaction->amount_base,
            'amount_local' => (float) $transaction->amount_local,
            'exchange_rate' => $transaction->exchange_rate === null ? null : (float) $transaction->exchange_rate,
            'method' => $transaction->method,
            'reference' => $transaction->reference,
            'occurred_at' => $transaction->occurred_at?->toISOString(),
        ]], Response::HTTP_CREATED);
    }
}
