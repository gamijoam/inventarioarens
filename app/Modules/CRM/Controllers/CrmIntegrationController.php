<?php

namespace App\Modules\CRM\Controllers;

use App\Modules\Branches\Models\Branch;
use App\Modules\CRM\Models\CrmApiToken;
use App\Modules\CRM\Requests\CrmAvailabilityRequest;
use App\Modules\CRM\Requests\CrmCatalogProductsRequest;
use App\Modules\CRM\Requests\CrmLocationsRequest;
use App\Modules\CRM\Resources\CrmAvailabilityResource;
use App\Modules\CRM\Resources\CrmBranchResource;
use App\Modules\CRM\Resources\CrmProductResource;
use App\Modules\CRM\Resources\CrmWarehouseResource;
use App\Modules\CRM\Services\CrmScopeService;
use App\Modules\Products\Models\Product;
use App\Modules\Sync\Models\SyncState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

class CrmIntegrationController extends Controller
{
    public function branches(CrmLocationsRequest $request, CrmScopeService $scope): AnonymousResourceCollection
    {
        $token = $this->token($request);
        $query = Branch::query()->orderBy('name');
        $this->applyStatus($query, $request->validated('status'));

        if (($branchIds = $scope->branchIds($token)) !== null) {
            $query->whereIn('id', $branchIds);
        }

        return CrmBranchResource::collection($query->paginate($this->perPage($request)));
    }

    public function warehouses(CrmLocationsRequest $request, CrmScopeService $scope): AnonymousResourceCollection
    {
        $token = $this->token($request);
        $query = $scope->warehouses($token)->with('branch')->orderBy('name');
        $this->applyStatus($query, $request->validated('status'));

        if ($request->filled('branch_id')) {
            $branchId = (int) $request->validated('branch_id');
            $scope->assertBranchAllowed($token, $branchId);
            $query->where('branch_id', $branchId);
        }

        return CrmWarehouseResource::collection($query->paginate($this->perPage($request)));
    }

    public function products(CrmCatalogProductsRequest $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->with(['brand', 'categories'])
            ->where('is_active', true)
            ->orderBy('name');

        if ($search = trim((string) $request->validated('search', ''))) {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $products) use ($like): void {
                $products
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(sku, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(barcode, \'\')) LIKE ?', [$like]);
            });
        }

        return CrmProductResource::collection($query->paginate($this->perPage($request)));
    }

    public function product(string $sku): CrmProductResource
    {
        $product = Product::query()
            ->with(['brand', 'categories'])
            ->where('is_active', true)
            ->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])
            ->firstOrFail();

        return CrmProductResource::make($product);
    }

    public function availability(CrmAvailabilityRequest $request, CrmScopeService $scope): AnonymousResourceCollection
    {
        $token = $this->token($request);
        $warehouseQuery = $scope->warehouses($token);

        $branchId = $request->filled('branch_id') ? (int) $request->validated('branch_id') : null;
        if ($branchId !== null) {
            $scope->assertBranchAllowed($token, $branchId);
            $warehouseQuery->where('branch_id', $branchId);
        }

        if ($request->filled('warehouse_id')) {
            $scope->assertWarehouseAllowed(
                $token,
                (int) $request->validated('warehouse_id'),
                $branchId,
            );
            $warehouseQuery->whereKey((int) $request->validated('warehouse_id'));
        }

        $warehouseIds = $warehouseQuery->pluck('id')->map(fn ($id) => (int) $id)->all();
        $query = Product::query()
            ->where('is_active', true)
            ->with(['stockBalances' => function ($balances) use ($warehouseIds): void {
                $balances->whereIn('warehouse_id', $warehouseIds)->with('warehouse.branch');
            }])
            ->orderBy('name');

        if ($warehouseIds === []) {
            $query->whereIn('id', []);
        }

        if ($request->filled('sku')) {
            $query->whereRaw('LOWER(sku) = ?', [mb_strtolower((string) $request->validated('sku'))]);
        }
        if ($request->filled('product_id')) {
            $query->whereKey((int) $request->validated('product_id'));
        }
        if ($search = trim((string) $request->validated('search', ''))) {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $products) use ($like): void {
                $products
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(sku, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(barcode, \'\')) LIKE ?', [$like]);
            });
        }

        $lastSyncAt = $this->lastSyncAt();
        $products = $query->paginate($this->perPage($request));
        $products->getCollection()->each(fn (Product $product) => $product->setAttribute('crm_last_sync_at', $lastSyncAt));

        return CrmAvailabilityResource::collection($products);
    }

    private function token(Request $request): CrmApiToken
    {
        return $request->attributes->get('crm_token');
    }

    private function applyStatus(Builder $query, ?string $status): void
    {
        if ($status === null) {
            $query->where('status', 'active');
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) ($request->validated('per_page') ?? 25)));
    }

    private function lastSyncAt(): ?Carbon
    {
        $last = SyncState::query()->orderByDesc('last_success_at')->first()?->last_success_at;

        return $last;
    }
}
