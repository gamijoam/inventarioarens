<?php

namespace App\Modules\AccessControl\Services;

use App\Models\User;
use App\Modules\AccessControl\Models\UserBranchScope;
use App\Modules\AccessControl\Models\UserCustomerGroupScope;
use App\Modules\AccessControl\Models\UserVendorAssignment;
use App\Modules\AccessControl\Models\UserWarehouseScope;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ScopeResolver
{
    public const SCOPE_NONE = 'none';
    public const SCOPE_ALLOW = 'allow';
    public const SCOPE_RESTRICT = 'restrict';

    /**
     * Devuelve los IDs de branches asignados al user, o null si no tiene scope (default-allow).
     * Devuelve array vacio si tiene scope pero esta vacio (no debe pasar en uso normal).
     */
    public function branchIdsFor(User $user): ?array
    {
        return $this->fetchIds(UserBranchScope::class, 'branch_id', $user);
    }

    public function warehouseIdsFor(User $user): ?array
    {
        return $this->fetchIds(UserWarehouseScope::class, 'warehouse_id', $user);
    }

    public function customerGroupIdsFor(User $user): ?array
    {
        return $this->fetchIds(UserCustomerGroupScope::class, 'customer_group_id', $user);
    }

    public function vendorOfGroupIdsFor(User $user): ?array
    {
        return $this->fetchIds(UserVendorAssignment::class, 'customer_group_id', $user);
    }

    /**
     * Devuelve el estado del scope: none|allow|restrict.
     * - none: sin scope asignado (default-allow, ve todo).
     * - allow: scope asignado pero vacio (default-allow, ve todo).
     * - restrict: scope asignado con IDs (ve solo esos).
     */
    public function statusFor(User $user): string
    {
        $branchIds = $this->branchIdsFor($user);
        if ($branchIds === null) {
            return self::SCOPE_NONE;
        }
        if (empty($branchIds)) {
            return self::SCOPE_ALLOW;
        }
        return self::SCOPE_RESTRICT;
    }

    /**
     * Aplica el scope de branches a la query. Si el user no tiene scope (null), no filtra.
     * Si tiene scope y la lista esta vacia, no filtra (default-allow).
     * Si tiene scope con IDs, filtra con whereIn.
     */
    public function applyBranchScope(Builder $query, User $user, string $column = 'branch_id'): Builder
    {
        return $this->applyScope($query, $this->branchIdsFor($user), $column);
    }

    public function applyWarehouseScope(Builder $query, User $user, string $column = 'warehouse_id'): Builder
    {
        return $this->applyScope($query, $this->warehouseIdsFor($user), $column);
    }

    public function applyCustomerGroupScope(Builder $query, User $user, string $column = 'customer_group_id'): Builder
    {
        return $this->applyScope($query, $this->customerGroupIdsFor($user), $column);
    }

    /**
     * Aplica scope de vendor: el query filtra por `user_id` (las ventas/cxc del user actual).
     * Se usa en CxC y ventas para mostrar "lo que YO vendi/atendi".
     */
    public function applyVendorScope(Builder $query, User $user, string $column = 'user_id'): Builder
    {
        return $this->applyScope($query, $this->vendorOfGroupIdsFor($user), $column);
    }

    /**
     * Reemplaza TODOS los scopes del user en el tenant para un tipo especifico.
     * Idempotente. $scopeTypeClass es la clase del modelo pivote.
     * $resourceKey es el nombre de la columna FK del recurso (branch_id, warehouse_id, etc).
     * $resourceIds es la lista de IDs. Array vacio = sin restricciones (default-allow).
     */
    public function replaceScope(User $user, string $scopeTypeClass, string $resourceKey, array $resourceIds, ?\App\Models\User $actor = null): void
    {
        $tenant = app(TenantManager::class)->require();

        DB::transaction(function () use ($user, $tenant, $scopeTypeClass, $resourceKey, $resourceIds): void {
            $scopeTypeClass::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->delete();

            foreach (array_unique($resourceIds) as $resourceId) {
                $scopeTypeClass::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    $resourceKey => $resourceId,
                ]);
            }
        });
    }

    private function fetchIds(string $modelClass, string $column, User $user): ?array
    {
        $tenant = app(TenantManager::class)->require();
        $tenantId = $tenant->id;

        $exists = $modelClass::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $exists) {
            return null;
        }

        return $modelClass::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->pluck($column)
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();
    }

    private function applyScope(Builder $query, ?array $ids, string $column): Builder
    {
        if ($ids === null) {
            return $query;
        }

        if (empty($ids)) {
            return $query;
        }

        return $query->whereIn($column, $ids);
    }
}