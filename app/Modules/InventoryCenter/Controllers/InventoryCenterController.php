<?php

namespace App\Modules\InventoryCenter\Controllers;

use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryCenter\Requests\InventoryCenterBulkActionRequest;
use App\Modules\InventoryCenter\Requests\InventoryCenterProductAuditsRequest;
use App\Modules\InventoryCenter\Requests\InventoryCenterProductMovementsRequest;
use App\Modules\InventoryCenter\Requests\InventoryCenterProductSerialsRequest;
use App\Modules\InventoryCenter\Requests\InventoryCenterSummaryRequest;
use App\Modules\InventoryCenter\Requests\RecalculateProductPriceRequest;
use App\Modules\InventoryCenter\Requests\ReorderSuggestionsRequest;
use App\Modules\InventoryCenter\Requests\UpdateProductProfitMarginRequest;
use App\Modules\InventoryCenter\Services\InventoryAlertService;
use App\Modules\InventoryCenter\Services\InventoryCenterBulkActionService;
use App\Modules\InventoryCenter\Services\InventoryCenterMovementService;
use App\Modules\InventoryCenter\Services\InventoryCenterProductDetailService;
use App\Modules\InventoryCenter\Services\InventoryCenterSummaryService;
use App\Modules\InventoryCenter\Services\RecalculatePriceService;
use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class InventoryCenterController extends Controller
{
    public function summary(InventoryCenterSummaryRequest $request, InventoryCenterSummaryService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->summary($request->validated()),
        ]);
    }

    public function export(InventoryCenterSummaryRequest $request, InventoryCenterSummaryService $service): Response
    {
        $filename = 'inventario_'.now()->format('Ymd_His').'.csv';

        return response($service->exportCsv($request->validated()), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function bulkAction(InventoryCenterBulkActionRequest $request, InventoryCenterBulkActionService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->apply($request->validated(), $request->user()?->id),
        ]);
    }

    public function movements(InventoryCenterProductMovementsRequest $request, InventoryCenterMovementService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->page($request->validated()),
        ]);
    }

    public function product(Product $product, InventoryCenterProductDetailService $service): JsonResponse
    {
        Gate::authorize('view', $product);

        return response()->json([
            'data' => $service->detail($product),
        ]);
    }

    public function productSerials(
        InventoryCenterProductSerialsRequest $request,
        Product $product,
        InventoryCenterProductDetailService $service
    ): JsonResponse {
        Gate::authorize('view', $product);

        return response()->json([
            'data' => $service->serialsPage($product, $request->validated()),
        ]);
    }

    public function productMovements(
        InventoryCenterProductMovementsRequest $request,
        Product $product,
        InventoryCenterProductDetailService $service
    ): JsonResponse {
        Gate::authorize('view', $product);

        return response()->json([
            'data' => $service->movementsPage($product, $request->validated()),
        ]);
    }

    public function productAudits(
        InventoryCenterProductAuditsRequest $request,
        Product $product,
        InventoryCenterProductDetailService $service
    ): JsonResponse {
        Gate::authorize('view', $product);

        return response()->json([
            'data' => $service->auditsPage($product, $request->validated()),
        ]);
    }

    public function productStockByWarehouse(Product $product, InventoryCenterProductDetailService $service): JsonResponse
    {
        Gate::authorize('view', $product);

        return response()->json([
            'data' => $service->stockByWarehousePage($product),
        ]);
    }

    /**
     * Contexto completo de stock para un producto en un warehouse
     * especifico. Devuelve disponible, reservado, total en otros
     * warehouses del tenant y en transito (transferencias incoming).
     * Usado por el badge del carrito del POS para informar al cajero
     * sin tener que abrir la pantalla de detalle del producto.
     *
     * Multi-tenancy: solo cuenta warehouses y transferencias del tenant
     * activo. El warehouse principal se valida contra el tenant.
     */
    public function productStockContext(
        Product $product,
        Request $request,
        InventoryCenterProductDetailService $service,
    ): JsonResponse {
        Gate::authorize('view', $product);

        $warehouseId = (int) $request->query('warehouse_id', 0);

        return response()->json([
            'data' => $service->stockContext($product, $warehouseId),
        ]);
    }

    public function productStockStatus(Product $product, InventoryAlertService $alerts): JsonResponse
    {
        Gate::authorize('view', $product);

        return response()->json([
            'data' => $alerts->stockStatus($product),
        ]);
    }

    public function reorderSuggestions(ReorderSuggestionsRequest $request, InventoryAlertService $alerts): JsonResponse
    {
        return response()->json([
            'data' => $alerts->reorderSuggestions($request->validated()),
        ]);
    }

    public function alertsSummary(Request $request, InventoryAlertService $alerts): JsonResponse
    {
        $threshold = (float) $request->input('fallback_threshold', 3);

        return response()->json([
            'data' => $alerts->summary($threshold),
        ]);
    }

    /**
     * POST /api/inventory-center/products/{product}/recalculate-price
     *
     * Recalcula el base_price = last_purchase_cost * (1 + profit_margin / 100).
     * Acepta `profit_margin` opcional en el body para override y guardar.
     */
    public function recalculateProductPrice(
        RecalculateProductPriceRequest $request,
        Product $product,
        RecalculatePriceService $service
    ): JsonResponse {
        Gate::authorize('update', $product);

        $override = $request->filled('profit_margin') ? (float) $request->input('profit_margin') : null;
        $result = $service->recalculate($product, $override);

        return response()->json([
            'data' => [
                'product_id' => $product->id,
                'base_price' => $result['base_price'],
                'profit_margin' => $result['profit_margin'],
                'last_purchase_cost' => $result['last_purchase_cost'],
            ],
        ]);
    }

    /**
     * PATCH /api/inventory-center/products/{product}/profit-margin
     *
     * Actualiza el profit_margin y (si hay WAC) recalcula el base_price
     * automaticamente.
     */
    public function updateProductProfitMargin(
        UpdateProductProfitMarginRequest $request,
        Product $product,
        RecalculatePriceService $service
    ): JsonResponse {
        Gate::authorize('update', $product);

        $margin = (float) $request->input('profit_margin');
        $product->profit_margin = round($margin, 2);

        $newBasePrice = null;
        if ($product->pricing_mode === Product::PRICING_AUTOMATIC && $product->last_purchase_cost !== null) {
            $newBasePrice = round((float) $product->last_purchase_cost * (1 + ($margin / 100)), 2);
            $product->base_price = $newBasePrice;
        }

        $product->save();

        return response()->json([
            'data' => [
                'product_id' => $product->id,
                'profit_margin' => (float) $product->profit_margin,
                'base_price' => $newBasePrice,
            ],
        ]);
    }

    /**
     * Ajuste directo y exacto de stock para un producto en uno o mas almacenes.
     * Permite fijar el stock al valor deseado (positivo, cero o negativo),
     * registrando el movimiento correspondiente (adjustment_in o adjustment_out)
     * y actualizando directamente el balance del producto.
     */
    public function setProductStock(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('update', $product);

        $payload = $request->validate([
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'quantity' => 'nullable|numeric',
            'reason' => 'nullable|string|max:255',
            'stocks' => 'nullable|array',
            'stocks.*.warehouse_id' => 'required_with:stocks|integer|exists:warehouses,id',
            'stocks.*.quantity' => 'required_with:stocks|numeric',
            'stocks.*.reason' => 'nullable|string|max:255',
        ]);

        $items = ! empty($payload['stocks'])
            ? $payload['stocks']
            : [
                [
                    'warehouse_id' => (int) ($payload['warehouse_id'] ?? 0),
                    'quantity' => (float) ($payload['quantity'] ?? 0),
                    'reason' => $payload['reason'] ?? 'Ajuste directo desde catálogo',
                ],
            ];

        $results = [];

        DB::transaction(function () use ($items, $product, $request, &$results) {
            foreach ($items as $item) {
                $whId = (int) ($item['warehouse_id'] ?? 0);
                if ($whId <= 0) {
                    continue;
                }

                $warehouse = Warehouse::query()->findOrFail($whId);
                $targetQuantity = (float) $item['quantity'];

                $balance = StockBalance::firstOrCreate(
                    [
                        'warehouse_id' => $whId,
                        'product_id' => $product->id,
                        'product_variant_id' => null,
                    ],
                    [
                        'quantity_available' => 0,
                        'quantity_reserved' => 0,
                        'quantity_damaged' => 0,
                    ]
                );

                $currentAvailable = (float) $balance->quantity_available;
                $diff = $targetQuantity - $currentAvailable;

                if (abs($diff) > 0.00001) {
                    $type = $diff > 0 ? 'adjustment_in' : 'adjustment_out';
                    $qty = abs($diff);

                    $balance->quantity_available = $targetQuantity;
                    $balance->save();

                    StockMovement::create([
                        'type' => $type,
                        'warehouse_id' => $whId,
                        'product_id' => $product->id,
                        'product_variant_id' => null,
                        'quantity' => $qty,
                        'unit_cost' => $product->average_cost ?? $product->last_purchase_cost,
                        'created_by' => $request->user()?->id,
                        'reason' => $item['reason'] ?? 'Ajuste rápido desde catálogo',
                        'reference_type' => 'catalog_quick_stock_set',
                        'reference_id' => null,
                    ]);
                }

                $results[] = [
                    'warehouse_id' => $whId,
                    'warehouse_name' => $warehouse->name,
                    'previous_quantity' => $currentAvailable,
                    'new_quantity' => $targetQuantity,
                    'diff' => $diff,
                ];
            }
        });

        // Refrescar total de stock del producto
        $totalAvailable = (float) StockBalance::where('product_id', $product->id)->sum('quantity_available');

        return response()->json([
            'message' => 'Stock actualizado correctamente.',
            'data' => [
                'product_id' => $product->id,
                'available_stock' => $totalAvailable,
                'warehouses' => $results,
            ],
        ]);
    }
}
