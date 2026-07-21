<?php

namespace App\Modules\POS\Controllers;

use App\Models\User;
use App\Modules\AccessControl\Services\ScopeResolver;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Resources\CashRegisterSessionResource;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Models\PriceList;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PosBootstrapController extends Controller
{
    public function __construct(private readonly ScopeResolver $scopes) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = app(TenantManager::class)->current()?->id;

        $response = [
            'warehouses' => Warehouse::query()
                ->with('branch:id,name,code')
                ->orderBy('name')
                ->get(['id', 'branch_id', 'code', 'name', 'status'])
                ->map(fn (Warehouse $warehouse) => [
                    'id' => $warehouse->id,
                    'branch_id' => $warehouse->branch_id,
                    'code' => $warehouse->code,
                    'name' => $warehouse->name,
                    'status' => $warehouse->status,
                    'branch_name' => $warehouse->branch?->name,
                    'branch_code' => $warehouse->branch?->code,
                ])
                ->all(),
            'branches' => Branch::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
                ->map(fn (Branch $branch) => [
                    'id' => $branch->id,
                    'code' => $branch->code,
                    'name' => $branch->name,
                ])
                ->all(),
            'cash_registers' => CashRegister::query()
                ->with('branch:id,name,code')
                ->where('status', CashRegister::STATUS_ACTIVE)
                ->orderBy('code')
                ->get(['id', 'branch_id', 'code', 'name', 'status'])
                ->map(fn (CashRegister $register) => [
                    'id' => $register->id,
                    'branch_id' => $register->branch_id,
                    'code' => $register->code,
                    'name' => $register->name,
                    'branch_name' => $register->branch?->name,
                ])
                ->all(),
            'payment_methods' => PaymentMethod::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'code', 'name', 'method', 'currency_mode', 'is_active'])
                ->map(fn (PaymentMethod $method) => [
                    'id' => $method->id,
                    'code' => $method->code,
                    'name' => $method->name,
                    'method' => $method->method,
                    'currency_mode' => $method->currency_mode,
                ])
                ->all(),
            'price_lists' => PriceList::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'is_default', 'is_active'])
                ->map(fn (PriceList $list) => [
                    'id' => $list->id,
                    'code' => $list->code,
                    'name' => $list->name,
                    'is_default' => (bool) $list->is_default,
                ])
                ->all(),
            'exchange_rate_types' => ExchangeRateType::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'is_default', 'is_active'])
                ->map(fn (ExchangeRateType $type) => [
                    'id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'is_default' => (bool) $type->is_default,
                ])
                ->all(),
            'exchange_rates' => ExchangeRate::query()
                ->where('is_active', true)
                ->whereIn('exchange_rate_type_id', ExchangeRateType::query()->where('is_active', true)->pluck('id'))
                ->orderByDesc('effective_at')
                ->get(['id', 'exchange_rate_type_id', 'base_currency', 'quote_currency', 'rate', 'effective_at'])
                ->map(fn (ExchangeRate $rate) => [
                    'id' => $rate->id,
                    'exchange_rate_type_id' => $rate->exchange_rate_type_id,
                    'base_currency' => $rate->base_currency,
                    'quote_currency' => $rate->quote_currency,
                    'rate' => (float) $rate->rate,
                    'effective_at' => $rate->effective_at?->toISOString(),
                ])
                ->all(),
            'open_session' => $this->resolveOpenSession($user, $tenantId),
        ];

        return response()->json($response);
    }

    private function resolveOpenSession(User $user, ?int $tenantId): ?array
    {
        $query = CashRegisterSession::query()
            ->with(['branch:id,name,code', 'cashRegister:id,code,name,branch_id'])
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->where('cashier_id', $user->id);

        $query = $this->scopes->applyBranchScope($query, $user, 'branch_id');

        $session = $query->latest('opened_at')->first();

        return $session ? (new CashRegisterSessionResource($session))->resolve() : null;
    }
}
