<?php

namespace App\Modules\CRM\Services;

use App\Modules\Branches\Models\Branch;
use App\Modules\CRM\Models\CrmApiToken;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;

class CrmScopeService
{
    public const EXCLUDED_BRANCH_SLUGS = ['tiendas-arens'];

    public function branchIds(CrmApiToken $token): ?array
    {
        $branchIds = $this->normalize($token->branch_ids);
        $warehouseIds = $this->normalize($token->warehouse_ids);

        if ($branchIds === null && $warehouseIds === null) {
            return null;
        }

        $query = $this->visibleBranches(Branch::query());
        if ($branchIds !== null) {
            $query->whereIn('id', $branchIds);
        }
        if ($warehouseIds !== null) {
            $query->whereHas('warehouses', fn (Builder $warehouses) => $warehouses->whereIn('id', $warehouseIds));
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function warehouses(CrmApiToken $token): Builder
    {
        $query = $this->visibleWarehouses(Warehouse::query());
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
        if (! $this->visibleBranches(Branch::query())
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
        if (! $this->visibleWarehouses(Warehouse::query())
            ->where('status', Warehouse::STATUS_ACTIVE)
            ->whereKey($warehouseId)
            ->exists()) {
            $this->notFound('El almacén solicitado no existe.');
        }

        $warehouse = $this->warehouses($token)->with('branch')->whereKey($warehouseId)->first();
        if (! $warehouse || ($branchId !== null && (int) $warehouse->branch_id !== $branchId)) {
            $this->deny('El almacén no está autorizado para esta credencial CRM.');
        }

        return $warehouse;
    }

    private function normalize(?array $ids): ?array
    {
        return $ids === null ? null : array_values(array_map('intval', $ids));
    }

    public function visibleBranches(Builder $query): Builder
    {
        return $query->whereNotIn('slug', self::EXCLUDED_BRANCH_SLUGS);
    }

    public function visibleWarehouses(Builder $query): Builder
    {
        return $query->whereHas('branch', function (Builder $branch): void {
            $this->visibleBranches($branch)->where('status', Branch::STATUS_ACTIVE);
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
