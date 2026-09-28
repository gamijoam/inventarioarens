<?php

namespace App\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Products\Models\BulkPriceAdjustment;
use App\Modules\Products\Services\BulkPriceAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BulkPriceAdjustmentController extends Controller
{
    public function __construct(
        protected BulkPriceAdjustmentService $service
    ) {}

    public function simulate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target' => 'required|string|in:base_price,price_list',
            'price_list_id' => 'nullable|required_if:target,price_list|exists:price_lists,id',
            'adjustment_type' => 'required|string|in:percentage_increase,percentage_decrease,fixed_amount,markup_on_cost',
            'adjustment_value' => 'required|numeric',
            'rounding' => 'nullable|string|in:none,integer,cents_99,cents_50',
            'filters' => 'nullable|array',
            'filters.product_ids' => 'nullable|array',
            'filters.product_ids.*' => 'integer|exists:products,id',
            'filters.category_id' => 'nullable|integer|exists:categories,id',
            'filters.brand_id' => 'nullable|integer|exists:brands,id',
            'filters.search' => 'nullable|string',
        ]);

        $filters = $validated['filters'] ?? [];
        $rules = [
            'target' => $validated['target'],
            'price_list_id' => $validated['price_list_id'] ?? null,
            'adjustment_type' => $validated['adjustment_type'],
            'adjustment_value' => (float) $validated['adjustment_value'],
            'rounding' => $validated['rounding'] ?? 'none',
        ];

        $preview = $this->service->simulate($filters, $rules);

        return response()->json($preview);
    }

    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'target' => 'required|string|in:base_price,price_list',
            'price_list_id' => 'nullable|required_if:target,price_list|exists:price_lists,id',
            'adjustment_type' => 'required|string|in:percentage_increase,percentage_decrease,fixed_amount,markup_on_cost',
            'adjustment_value' => 'required|numeric',
            'rounding' => 'nullable|string|in:none,integer,cents_99,cents_50',
            'filters' => 'nullable|array',
            'filters.product_ids' => 'nullable|array',
            'filters.product_ids.*' => 'integer|exists:products,id',
            'filters.category_id' => 'nullable|integer|exists:categories,id',
            'filters.brand_id' => 'nullable|integer|exists:brands,id',
            'filters.search' => 'nullable|string',
        ]);

        $filters = $validated['filters'] ?? [];
        $rules = [
            'target' => $validated['target'],
            'price_list_id' => $validated['price_list_id'] ?? null,
            'adjustment_type' => $validated['adjustment_type'],
            'adjustment_value' => (float) $validated['adjustment_value'],
            'rounding' => $validated['rounding'] ?? 'none',
        ];

        $adjustment = $this->service->apply(
            user: $request->user(),
            filters: $filters,
            rules: $rules,
            name: $validated['name'] ?? null
        );

        return response()->json([
            'message' => 'Ajuste masivo de precios aplicado exitosamente.',
            'adjustment' => $adjustment,
        ], 201);
    }

    public function rollback(BulkPriceAdjustment $adjustment, Request $request): JsonResponse
    {
        $reverted = $this->service->rollback($adjustment, $request->user());

        return response()->json([
            'message' => 'Ajuste de precios revertido exitosamente.',
            'adjustment' => $reverted,
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $adjustments = BulkPriceAdjustment::with(['user:id,name,email', 'revertedBy:id,name,email', 'priceList:id,name'])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json($adjustments);
    }
}
