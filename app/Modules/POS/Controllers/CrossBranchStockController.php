<?php

namespace App\Modules\POS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrossBranchStockController extends Controller
{
    public function __invoke(Request $request, Product $product): JsonResponse
    {
        $currentTenant = app(TenantManager::class)->require();

        // 1. Resolver el grupo raíz
        $rootGroup = null;
        if ($currentTenant->is_group) {
            $rootGroup = $currentTenant;
        } elseif ($currentTenant->parent_id) {
            $rootGroup = Tenant::withoutGlobalScopes()->find($currentTenant->parent_id);
        }

        if (! $rootGroup) {
            return response()->json([
                'data' => [
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'barcode' => $product->barcode,
                    ],
                    'branches' => [],
                ],
            ]);
        }

        // 2. Determinar tenants relacionados (hijas del grupo + el grupo si no es el actual)
        $siblingTenants = Tenant::withoutGlobalScopes()
            ->where(function ($q) use ($rootGroup) {
                $q->where('parent_id', $rootGroup->id)
                    ->orWhere('id', $rootGroup->id);
            })
            ->where('id', '!=', $currentTenant->id)
            ->where('status', 'active')
            ->get();

        // Identificar el ID del producto maestro en el catálogo
        $masterProductId = $product->is_catalog_master ? $product->id : $product->catalog_product_id;

        $branches = [];

        foreach ($siblingTenants as $sibling) {
            // Buscar el producto en la empresa hermana
            $matchedProduct = null;

            if ($masterProductId) {
                $matchedProduct = Product::withoutGlobalScopes()
                    ->where('tenant_id', $sibling->id)
                    ->where(function ($q) use ($masterProductId) {
                        $q->where('id', $masterProductId)
                            ->orWhere('catalog_product_id', $masterProductId);
                    })
                    ->first();
            }

            if (! $matchedProduct && ($product->sku || $product->barcode)) {
                $matchedProduct = Product::withoutGlobalScopes()
                    ->where('tenant_id', $sibling->id)
                    ->where(function ($q) use ($product) {
                        if ($product->sku) {
                            $q->where('sku', $product->sku);
                        }
                        if ($product->barcode) {
                            $q->orWhere('barcode', $product->barcode);
                        }
                    })
                    ->first();
            }

            if (! $matchedProduct) {
                continue;
            }

            // Consultar existencias por almacén en esa empresa
            $stockBalances = StockBalance::withoutGlobalScopes()
                ->where('tenant_id', $sibling->id)
                ->where('product_id', $matchedProduct->id)
                ->get();

            $warehouseIds = $stockBalances->pluck('warehouse_id')->filter()->unique();
            $warehousesMap = \App\Modules\Warehouses\Models\Warehouse::withoutGlobalScopes()
                ->whereIn('id', $warehouseIds)
                ->get()
                ->keyBy('id');

            $warehouses = [];
            $totalAvailable = 0;

            foreach ($stockBalances as $sb) {
                $avail = (float) $sb->quantity_available;
                $resv = (float) $sb->quantity_reserved;
                $totalAvailable += $avail;

                $wh = $warehousesMap->get($sb->warehouse_id);

                $warehouses[] = [
                    'warehouse_id' => $sb->warehouse_id,
                    'warehouse_name' => $wh?->name ?? 'Almacén',
                    'warehouse_code' => $wh?->code ?? '',
                    'quantity_available' => $avail,
                    'quantity_reserved' => $resv,
                ];
            }

            $branches[] = [
                'tenant_id' => $sibling->id,
                'tenant_name' => $sibling->name,
                'tenant_slug' => $sibling->slug,
                'is_group' => (bool) $sibling->is_group,
                'matched_product_id' => $matchedProduct->id,
                'total_available' => $totalAvailable,
                'warehouses' => $warehouses,
            ];
        }

        return response()->json([
            'data' => [
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                ],
                'branches' => $branches,
            ],
        ]);
    }
}
