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
use App\Modules\Tenancy\Models\Tenant;
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
        $activeTenantIds = $this->activeTenantIdsFor($user);
        $tenantsById = $this->tenantsById($activeTenantIds);

        $response = [
            'warehouses' => $this->warehousesFor($activeTenantIds, $tenantsById),
            'branches' => $this->branchesFor($activeTenantIds, $tenantsById),
            'cash_registers' => $this->cashRegistersFor($activeTenantIds, $tenantsById),
            'payment_methods' => $this->paymentMethodsFor($activeTenantIds, $tenantsById),
            'price_lists' => $this->priceListsFor($activeTenantIds, $tenantsById),
            'exchange_rate_types' => $this->exchangeRateTypesFor($activeTenantIds, $tenantsById),
            'exchange_rates' => $this->exchangeRatesFor($activeTenantIds, $tenantsById),
            'open_session' => $this->resolveOpenSession($user, $tenantId, $activeTenantIds),
        ];

        return response()->json($response);
    }

    /**
     * IDs de los tenants donde el usuario es miembro activo.
     *
     * @return array<int, int>
     */
    private function activeTenantIdsFor(User $user): array
    {
        return $user->tenants()
            ->wherePivot('status', 'active')
            ->pluck('tenants.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Carga tenants por id para exponer su nombre/slug en el payload.
     *
     * @param  array<int, int>  $tenantIds
     * @return array<int, array{id:int,name:string,slug:string}>
     */
    private function tenantsById(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        return Tenant::query()
            ->whereIn('id', $tenantIds)
            ->get(['id', 'name', 'slug'])
            ->mapWithKeys(fn (Tenant $tenant): array => [
                $tenant->id => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                ],
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $tenantIds
     * @param  array<int, array{id:int,name:string,slug:string}>  $tenantsById
     * @return array<int, array<string, mixed>>
     */
    private function warehousesFor(array $tenantIds, array $tenantsById): array
    {
        if ($tenantIds === []) {
            return [];
        }

        return Warehouse::query()
            ->withoutGlobalScopes()
            ->with('branch:id,name,code')
            ->whereIn('tenant_id', $tenantIds)
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'branch_id', 'code', 'name', 'status'])
            ->map(fn (Warehouse $warehouse) => [
                'id' => $warehouse->id,
                'tenant_id' => $warehouse->tenant_id,
                'branch_id' => $warehouse->branch_id,
                'code' => $warehouse->code,
                'name' => $warehouse->name,
                'status' => $warehouse->status,
                'branch_name' => $warehouse->branch?->name,
                'branch_code' => $warehouse->branch?->code,
                'tenant_name' => $tenantsById[$warehouse->tenant_id]['name'] ?? null,
                'tenant_slug' => $tenantsById[$warehouse->tenant_id]['slug'] ?? null,
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $tenantIds
     * @param  array<int, array{id:int,name:string,slug:string}>  $tenantsById
     * @return array<int, array<string, mixed>>
     */
    private function branchesFor(array $tenantIds, array $tenantsById): array
    {
        if ($tenantIds === []) {
            return [];
        }

        return Branch::query()
            ->withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'code', 'name'])
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'tenant_id' => $branch->tenant_id,
                'code' => $branch->code,
                'name' => $branch->name,
                'tenant_name' => $tenantsById[$branch->tenant_id]['name'] ?? null,
                'tenant_slug' => $tenantsById[$branch->tenant_id]['slug'] ?? null,
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $tenantIds
     * @param  array<int, array{id:int,name:string,slug:string}>  $tenantsById
     * @return array<int, array<string, mixed>>
     */
    private function cashRegistersFor(array $tenantIds, array $tenantsById): array
    {
        if ($tenantIds === []) {
            return [];
        }

        return CashRegister::query()
            ->withoutGlobalScopes()
            ->with('branch:id,name,code')
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', CashRegister::STATUS_ACTIVE)
            ->orderBy('code')
            ->get(['id', 'tenant_id', 'branch_id', 'code', 'name', 'status'])
            ->map(fn (CashRegister $register) => [
                'id' => $register->id,
                'tenant_id' => $register->tenant_id,
                'branch_id' => $register->branch_id,
                'code' => $register->code,
                'name' => $register->name,
                'branch_name' => $register->branch?->name,
                'tenant_name' => $tenantsById[$register->tenant_id]['name'] ?? null,
                'tenant_slug' => $tenantsById[$register->tenant_id]['slug'] ?? null,
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $tenantIds
     * @param  array<int, array{id:int,name:string,slug:string}>  $tenantsById
     * @return array<int, array<string, mixed>>
     */
    private function paymentMethodsFor(array $tenantIds, array $tenantsById): array
    {
        if ($tenantIds === []) {
            return [];
        }

        return PaymentMethod::query()
            ->withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'code', 'name', 'method', 'currency_mode', 'is_active'])
            ->map(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'tenant_id' => $method->tenant_id,
                'code' => $method->code,
                'name' => $method->name,
                'method' => $method->method,
                'currency_mode' => $method->currency_mode,
                'tenant_name' => $tenantsById[$method->tenant_id]['name'] ?? null,
                'tenant_slug' => $tenantsById[$method->tenant_id]['slug'] ?? null,
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $tenantIds
     * @param  array<int, array{id:int,name:string,slug:string}>  $tenantsById
     * @return array<int, array<string, mixed>>
     */
    private function priceListsFor(array $tenantIds, array $tenantsById): array
    {
        if ($tenantIds === []) {
            return [];
        }

        return PriceList::query()
            ->withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'code', 'name', 'is_default', 'is_active'])
            ->map(fn (PriceList $list) => [
                'id' => $list->id,
                'tenant_id' => $list->tenant_id,
                'code' => $list->code,
                'name' => $list->name,
                'is_default' => (bool) $list->is_default,
                'tenant_name' => $tenantsById[$list->tenant_id]['name'] ?? null,
                'tenant_slug' => $tenantsById[$list->tenant_id]['slug'] ?? null,
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $tenantIds
     * @param  array<int, array{id:int,name:string,slug:string}>  $tenantsById
     * @return array<int, array<string, mixed>>
     */
    private function exchangeRateTypesFor(array $tenantIds, array $tenantsById): array
    {
        if ($tenantIds === []) {
            return [];
        }

        return ExchangeRateType::query()
            ->withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'code', 'name', 'is_default', 'is_active'])
            ->map(fn (ExchangeRateType $type) => [
                'id' => $type->id,
                'tenant_id' => $type->tenant_id,
                'code' => $type->code,
                'name' => $type->name,
                'is_default' => (bool) $type->is_default,
                'tenant_name' => $tenantsById[$type->tenant_id]['name'] ?? null,
                'tenant_slug' => $tenantsById[$type->tenant_id]['slug'] ?? null,
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $tenantIds
     * @param  array<int, array{id:int,name:string,slug:string}>  $tenantsById
     * @return array<int, array<string, mixed>>
     */
    private function exchangeRatesFor(array $tenantIds, array $tenantsById): array
    {
        if ($tenantIds === []) {
            return [];
        }

        return ExchangeRate::query()
            ->withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->where('is_active', true)
            ->orderByDesc('effective_at')
            ->get(['id', 'tenant_id', 'exchange_rate_type_id', 'base_currency', 'quote_currency', 'rate', 'effective_at'])
            ->map(fn (ExchangeRate $rate) => [
                'id' => $rate->id,
                'tenant_id' => $rate->tenant_id,
                'exchange_rate_type_id' => $rate->exchange_rate_type_id,
                'base_currency' => $rate->base_currency,
                'quote_currency' => $rate->quote_currency,
                'rate' => (float) $rate->rate,
                'effective_at' => $rate->effective_at?->toISOString(),
                'tenant_name' => $tenantsById[$rate->tenant_id]['name'] ?? null,
                'tenant_slug' => $tenantsById[$rate->tenant_id]['slug'] ?? null,
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $activeTenantIds
     */
    private function resolveOpenSession(User $user, ?int $tenantId, array $activeTenantIds): ?array
    {
        if ($activeTenantIds === []) {
            return null;
        }

        $query = CashRegisterSession::query()
            ->withoutGlobalScopes()
            ->with(['branch:id,name,code', 'cashRegister:id,code,name,branch_id'])
            ->whereIn('tenant_id', $activeTenantIds)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->where('cashier_id', $user->id);

        $query = $this->scopes->applyBranchScope($query, $user, 'branch_id');

        $session = $query->latest('opened_at')->first();

        return $session ? (new CashRegisterSessionResource($session))->resolve() : null;
    }
}
