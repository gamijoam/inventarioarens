<?php

namespace App\Modules\CRM\Services;

use App\Modules\Branches\Models\Branch;
use App\Modules\CRM\Models\CrmApiToken;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;

class CrmScopeService
{
    public const EXCLUDED_BRANCH_SLUGS = ['tiendas-arens'];

    public function tenantIds(CrmApiToken $token): array
    {
        $tenant = $token->relationLoaded('tenant')
            ? $token->getRelation('tenant')
            : Tenant::query()->find($token->tenant_id);

        if (! $tenant) {
            return [];
        }

        return $this->tenantIdsFor($tenant, (string) $token->tenant_scope);
    }

    public function tenantIdsFor(Tenant $tenant, string $tenantScope): array
    {
        if ($tenantScope !== CrmApiToken::TENANT_SCOPE_SUBTREE) {
            return [(int) $tenant->id];
        }

        $descendantIds = [];
        $frontier = [(int) $tenant->id];
        $visitedIds = [(int) $tenant->id];

        while ($frontier !== []) {
            $children = Tenant::query()
                ->whereIn('parent_id', $frontier)
                ->where('status', 'active')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->reject(fn (int $id): bool => in_array($id, $visitedIds, true))
                ->values()
                ->all();

            if ($children === []) {
                break;
            }

            $descendantIds = array_values(array_unique([...$descendantIds, ...$children]));
            $visitedIds = array_values(array_unique([...$visitedIds, ...$children]));
            $frontier = $children;
        }

        return $descendantIds;
    }

    public function catalogProducts(CrmApiToken $token): Builder
    {
        $catalogTenantId = (int) $token->tenant_id;

        return Product::withoutGlobalScopes()
            ->where('tenant_id', $catalogTenantId)
            ->when(
                $token->tenant_scope === CrmApiToken::TENANT_SCOPE_SUBTREE,
                fn (Builder $products) => $products->where('is_catalog_master', true),
            );
    }

    public function branchIds(CrmApiToken $token): ?array
    {
        $branchIds = $this->normalize($token->branch_ids);
        $warehouseIds = $this->normalize($token->warehouse_ids);

        if ($branchIds === null && $warehouseIds === null) {
            return null;
        }

        $tenantIds = $this->tenantIds($token);
        $query = $this->visibleBranches(Branch::withoutGlobalScopes(), $tenantIds);
        if ($branchIds !== null) {
            $query->whereIn('id', $branchIds);
        }
        if ($warehouseIds !== null) {
            $query->whereHas('warehouses', function (Builder $warehouses) use ($tenantIds, $warehouseIds): void {
                $warehouses
                    ->withoutGlobalScopes()
                    ->whereIn('tenant_id', $tenantIds)
                    ->whereIn('id', $warehouseIds);
            });
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function warehouses(CrmApiToken $token): Builder
    {
        $query = $this->visibleWarehouses(Warehouse::withoutGlobalScopes(), $this->tenantIds($token));
        $branchIds = $this->normalize($token->branch_ids);
        $warehouseIds = $this->normalize($token->warehouse_ids);

        if ($branchIds !== null) {
            $query->whereIn('branch_id', $branchIds);
        }
        if ($warehouseIds !== null) {
            $query->whereIn('id', $warehouseIds);
        }

        return $query;
    }

    public function assertBranchAllowed(CrmApiToken $token, int $branchId): void
    {
        if (! $this->visibleBranches(Branch::withoutGlobalScopes(), $this->tenantIds($token))
            ->where('status', Branch::STATUS_ACTIVE)
            ->whereKey($branchId)
            ->exists()) {
            $this->notFound('La sucursal solicitada no existe.');
        }

        $allowed = $this->branchIds($token);
        if ($allowed !== null && ! in_array($branchId, $allowed, true)) {
            $this->deny('La sucursal no está autorizada para esta credencial CRM.');
        }
    }

    public function assertWarehouseAllowed(CrmApiToken $token, int $warehouseId, ?int $branchId = null): Warehouse
    {
        if (! $this->visibleWarehouses(Warehouse::withoutGlobalScopes(), $this->tenantIds($token))
            ->where('status', Warehouse::STATUS_ACTIVE)
            ->whereKey($warehouseId)
            ->exists()) {
            $this->notFound('El almacén solicitado no existe.');
        }

        $warehouse = $this->warehouses($token)
            ->with(['branch' => fn ($branch) => $branch->withoutGlobalScopes()->with('tenant')])
            ->whereKey($warehouseId)
            ->first();
        if (! $warehouse || ($branchId !== null && (int) $warehouse->branch_id !== $branchId)) {
            $this->deny('El almacén no está autorizado para esta credencial CRM.');
        }

        return $warehouse;
    }

    private function normalize(?array $ids): ?array
    {
        return $ids === null ? null : array_values(array_map('intval', $ids));
    }

    public function visibleBranches(Builder $query, ?array $tenantIds = null): Builder
    {
        return $query
            ->when($tenantIds !== null, fn (Builder $branches) => $branches->whereIn('tenant_id', $tenantIds))
            ->whereNotIn('slug', self::EXCLUDED_BRANCH_SLUGS);
    }

    public function visibleWarehouses(Builder $query, ?array $tenantIds = null): Builder
    {
        return $query->whereHas('branch', function (Builder $branch) use ($tenantIds): void {
            $this->visibleBranches($branch->withoutGlobalScopes(), $tenantIds)
                ->where('status', Branch::STATUS_ACTIVE);
        });
    }

    private function deny(string $message): never
    {
        abort(response()->json([
            'message' => $message,
            'error' => 'insufficient_scope',
        ], Response::HTTP_FORBIDDEN));
    }

    private function notFound(string $message): never
    {
        abort(response()->json([
            'message' => $message,
            'error' => 'not_found',
        ], Response::HTTP_NOT_FOUND));
    }
}
