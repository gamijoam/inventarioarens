<?php

namespace App\Modules\Products\Controllers;

use App\Modules\Products\Models\BulkPriceAdjustment;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAudit;
use App\Modules\Products\Models\ProductPackaging;
use App\Modules\Products\Services\ProductPriceService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Datos para imprimir etiquetas de anaquel: precio vigente en USD y Bs a la
 * tasa activa, codigo de barras y presentaciones con precio propio.
 */
class ProductLabelController extends Controller
{
    private const MAX_PRODUCTS = 500;

    public function __invoke(Request $request, ProductPriceService $prices): JsonResponse
    {
        abort_unless($request->user()->can('products.view'), 403);

        $tenantId = app(TenantManager::class)->require()->id;
        $data = $request->validate([
            'product_ids' => ['required_without_all:changed_since,bulk_adjustment_id', 'array', 'max:'.self::MAX_PRODUCTS],
            'product_ids.*' => ['integer'],
            'changed_since' => ['required_without_all:product_ids,bulk_adjustment_id', 'date'],
            'bulk_adjustment_id' => ['required_without_all:product_ids,changed_since', 'integer', Rule::exists('bulk_price_adjustments', 'id')->where('tenant_id', $tenantId)],
            'price_list_id' => ['nullable', 'integer', Rule::exists('price_lists', 'id')->where('tenant_id', $tenantId)],
        ]);

        $products = Product::query()
            ->whereIn('id', $this->productIds($data))
            ->orderBy('name')
            ->limit(self::MAX_PRODUCTS)
            ->get();
        $packagings = ProductPackaging::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->where('is_active', true)
            ->whereNotNull('price_usd')
            ->orderBy('factor')
            ->get()
            ->groupBy('product_id');

        $labels = [];
        $skipped = [];

        foreach ($products as $product) {
            try {
                $quote = $prices->quote($product, $data['price_list_id'] ?? null, isset($data['price_list_id']) ? ProductPriceService::PRICE_SOURCE_LIST : null);
            } catch (ValidationException $exception) {
                $skipped[] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'reason' => collect($exception->errors())->flatten()->first(),
                ];

                continue;
            }

            $rate = $quote['exchange_rate'] === null ? null : (float) $quote['exchange_rate'];
            $labels[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'unit_of_measure' => $product->unit_of_measure,
                'price_usd' => round((float) $quote['base_price_usd'], 4),
                'price_ves' => $quote['price_ves'] === null ? null : round((float) $quote['price_ves'], 2),
                'sale_currency' => $quote['sale_currency'],
                'price_list_name' => $quote['price_list_name'],
                'exchange_rate' => $rate,
                'exchange_rate_type_code' => $quote['exchange_rate_type_code'],
                'packagings' => ($packagings->get($product->id) ?? collect())->map(fn (ProductPackaging $packaging): array => [
                    'name' => $packaging->name,
                    'factor' => (float) $packaging->factor,
                    'barcode' => $packaging->barcode,
                    'price_usd' => round((float) $packaging->price_usd, 4),
                    'price_ves' => $rate === null ? null : round((float) $packaging->price_usd * $rate, 2),
                ])->values()->all(),
            ];
        }

        return response()->json(['data' => [
            'labels' => $labels,
            'skipped' => $skipped,
            'generated_at' => now()->toISOString(),
        ]]);
    }

    private function productIds(array $data): Collection
    {
        if (! empty($data['product_ids'])) {
            return collect($data['product_ids'])->map(fn ($id): int => (int) $id)->unique()->values();
        }

        if (! empty($data['bulk_adjustment_id'])) {
            $adjustment = BulkPriceAdjustment::query()->findOrFail($data['bulk_adjustment_id']);

            return collect($adjustment->snapshots ?? [])->pluck('product_id')->map(fn ($id): int => (int) $id)->unique()->values();
        }

        return ProductAudit::query()
            ->where('created_at', '>=', Carbon::parse($data['changed_since'])->startOfDay())
            ->get(['product_id', 'changes'])
            ->filter(fn (ProductAudit $audit): bool => $this->changedPrice($audit->changes ?? []))
            ->pluck('product_id')
            ->unique()
            ->values();
    }

    private function changedPrice(array $changes): bool
    {
        $before = $changes['before'] ?? [];
        $after = $changes['after'] ?? [];

        return array_key_exists('product_price', $after)
            || (array_key_exists('base_price', $before) && array_key_exists('base_price', $after));
    }
}
