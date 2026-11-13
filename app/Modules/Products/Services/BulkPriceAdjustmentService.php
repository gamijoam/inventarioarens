<?php

namespace App\Modules\Products\Services;

use App\Models\User;
use App\Modules\Products\Models\BulkPriceAdjustment;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAudit;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Support\Realtime\WsHub;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BulkPriceAdjustmentService
{
    public function simulate(array $filters, array $rules): array
    {
        $target = $rules['target'] ?? BulkPriceAdjustment::TARGET_BASE_PRICE;
        $adjustmentType = $rules['adjustment_type'] ?? BulkPriceAdjustment::TYPE_PERCENTAGE_INCREASE;
        $adjustmentValue = (float) ($rules['adjustment_value'] ?? 0);
        $rounding = $rules['rounding'] ?? 'none';
        $priceListId = $rules['price_list_id'] ?? null;

        $products = $this->queryProducts($filters)->get();

        $items = [];
        $totalDiffPercent = 0;

        foreach ($products as $product) {
            $cost = (float) ($product->average_cost ?? 0);

            if ($target === BulkPriceAdjustment::TARGET_BASE_PRICE) {
                $currentPrice = (float) $product->base_price;
            } else {
                $productPrice = ProductPrice::where('product_id', $product->id)
                    ->where('price_list_id', $priceListId)
                    ->first();
                $currentPrice = $productPrice ? (float) $productPrice->price : (float) $product->base_price;
            }

            $newPrice = $this->calculateNewPrice($currentPrice, $cost, $adjustmentType, $adjustmentValue);
            $newPrice = $this->applyRounding($newPrice, $rounding);

            if ($newPrice < 0) {
                $newPrice = 0.00;
            }

            $diffAmount = round($newPrice - $currentPrice, 2);
            $diffPercent = $currentPrice > 0 ? round((($newPrice - $currentPrice) / $currentPrice) * 100, 2) : 0.0;

            $marginBefore = $cost > 0 ? round((($currentPrice - $cost) / $cost) * 100, 2) : 0.0;
            $marginAfter = $cost > 0 ? round((($newPrice - $cost) / $cost) * 100, 2) : 0.0;

            $totalDiffPercent += $diffPercent;

            $items[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'cost' => $cost,
                'current_price' => $currentPrice,
                'new_price' => $newPrice,
                'diff_amount' => $diffAmount,
                'diff_percent' => $diffPercent,
                'margin_before' => $marginBefore,
                'margin_after' => $marginAfter,
            ];
        }

        $totalCount = count($items);
        $avgDiffPercent = $totalCount > 0 ? round($totalDiffPercent / $totalCount, 2) : 0.0;

        return [
            'items' => $items,
            'total_items' => $totalCount,
            'average_diff_percent' => $avgDiffPercent,
            'rules' => $rules,
            'filters' => $filters,
        ];
    }

    public function apply(User $user, array $filters, array $rules, ?string $name = null): BulkPriceAdjustment
    {
        $preview = $this->simulate($filters, $rules);
        $items = $preview['items'];

        if (empty($items)) {
            throw new InvalidArgumentException('No hay productos que coincidan con los filtros seleccionados.');
        }

        $target = $rules['target'] ?? BulkPriceAdjustment::TARGET_BASE_PRICE;
        $priceListId = $rules['price_list_id'] ?? null;
        $tenantId = app(TenantManager::class)->id();

        return DB::transaction(function () use ($user, $filters, $rules, $name, $items, $target, $priceListId, $tenantId) {
            $snapshots = [];

            foreach ($items as $item) {
                $snapshots[] = [
                    'product_id' => $item['product_id'],
                    'old_price' => $item['current_price'],
                    'new_price' => $item['new_price'],
                    'cost' => $item['cost'],
                ];

                if ($target === BulkPriceAdjustment::TARGET_BASE_PRICE) {
                    $this->setBasePrice((int) $item['product_id'], (float) $item['new_price'], $user);
                } else {
                    $this->setListPrice((int) $item['product_id'], (int) $priceListId, (float) $item['new_price'], $user, true);
                }
            }

            $adjustment = BulkPriceAdjustment::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'name' => $name ?: 'Ajuste masivo de precios ('.count($items).' productos)',
                'target' => $target,
                'price_list_id' => $priceListId,
                'adjustment_type' => $rules['adjustment_type'] ?? BulkPriceAdjustment::TYPE_PERCENTAGE_INCREASE,
                'adjustment_value' => $rules['adjustment_value'] ?? 0,
                'rounding' => $rules['rounding'] ?? 'none',
                'filters' => $filters,
                'items_count' => count($items),
                'snapshots' => $snapshots,
                'status' => BulkPriceAdjustment::STATUS_APPLIED,
            ]);

            // WebSocket event to notify all connected POS & ERP terminals
            if ($tenantId) {
                WsHub::publishTenant($tenantId, 'products.prices.updated', [
                    'adjustment_id' => $adjustment->id,
                    'target' => $target,
                    'price_list_id' => $priceListId,
                    'items_count' => count($items),
                    'user_name' => $user->name,
                    'timestamp' => now()->toIso8601String(),
                ]);
            }

            return $adjustment;
        });
    }

    public function rollback(BulkPriceAdjustment $adjustment, User $user): BulkPriceAdjustment
    {
        if ($adjustment->status !== BulkPriceAdjustment::STATUS_APPLIED) {
            throw new InvalidArgumentException('Este ajuste ya ha sido revertido o no está activo.');
        }

        return DB::transaction(function () use ($adjustment, $user) {
            $target = $adjustment->target;
            $priceListId = $adjustment->price_list_id;
            $snapshots = $adjustment->snapshots ?? [];

            foreach ($snapshots as $snap) {
                $oldPrice = $snap['old_price'];

                if ($target === BulkPriceAdjustment::TARGET_BASE_PRICE) {
                    $this->setBasePrice((int) $snap['product_id'], (float) $oldPrice, $user);
                } else {
                    $this->setListPrice((int) $snap['product_id'], (int) $priceListId, (float) $oldPrice, $user, false);
                }
            }

            $adjustment->update([
                'status' => BulkPriceAdjustment::STATUS_REVERTED,
                'reverted_at' => now(),
                'reverted_by' => $user->id,
            ]);

            // WebSocket event to notify rollback
            if ($adjustment->tenant_id) {
                WsHub::publishTenant($adjustment->tenant_id, 'products.prices.updated', [
                    'adjustment_id' => $adjustment->id,
                    'target' => $target,
                    'reverted' => true,
                    'items_count' => count($snapshots),
                    'user_name' => $user->name,
                    'timestamp' => now()->toIso8601String(),
                ]);
            }

            return $adjustment->refresh();
        });
    }

    /**
     * Cambia el precio base como una edicion normal: auditoria (historial de
     * precios), sincronizacion y propagacion a las copias del grupo.
     */
    private function setBasePrice(int $productId, float $price, User $user): void
    {
        $product = Product::query()->find($productId);

        if (! $product) {
            return;
        }

        $before = $product->base_price === null ? null : round((float) $product->base_price, 4);
        $product->base_price = $price;
        $product->save();

        $this->audit($product, ['base_price' => $before], ['base_price' => round($price, 4)], $user);
        app(SyncCatalogOutboxService::class)->productUpdated($product->refresh());

        if ($product->isCatalogMaster()) {
            app(SharedCatalogPropagationService::class)->syncMasterFieldsToCopies($product);
        }
    }

    private function setListPrice(int $productId, int $priceListId, float $price, User $user, bool $createIfMissing): void
    {
        $product = Product::query()->find($productId);
        $productPrice = ProductPrice::query()
            ->where('product_id', $productId)
            ->where('price_list_id', $priceListId)
            ->first();

        if (! $product || (! $productPrice && ! $createIfMissing)) {
            return;
        }

        $before = $productPrice ? $this->listPriceAudit($productPrice) : null;

        if ($productPrice) {
            $productPrice->update(['price' => $price, 'is_active' => true]);
        } else {
            $productPrice = ProductPrice::create([
                'product_id' => $productId,
                'price_list_id' => $priceListId,
                'price' => $price,
                'currency' => Product::CURRENCY_USD,
                'is_active' => true,
            ]);
        }

        $this->audit($product, ['product_price' => $before], ['product_price' => $this->listPriceAudit($productPrice->refresh())], $user);
        $before === null
            ? app(SyncCatalogOutboxService::class)->productPriceCreated($productPrice)
            : app(SyncCatalogOutboxService::class)->productPriceUpdated($productPrice);
    }

    private function listPriceAudit(ProductPrice $productPrice): array
    {
        return [
            'price_list_id' => $productPrice->price_list_id,
            'price' => round((float) $productPrice->price, 4),
            'currency' => $productPrice->currency,
            'exchange_rate_type_id' => $productPrice->exchange_rate_type_id,
            'is_active' => (bool) $productPrice->is_active,
        ];
    }

    private function audit(Product $product, array $before, array $after, User $user): void
    {
        ProductAudit::create([
            'product_id' => $product->id,
            'action' => ProductAudit::ACTION_UPDATED,
            'changes' => ['before' => $before, 'after' => $after],
            'created_by' => $user->id,
        ]);
    }

    private function queryProducts(array $filters): Builder
    {
        $query = Product::query();

        if (! empty($filters['product_ids'])) {
            $query->whereIn('id', $filters['product_ids']);
        }

        if (! empty($filters['category_id'])) {
            $query->whereHas('categories', function ($q) use ($filters) {
                $q->where('categories.id', $filters['category_id']);
            });
        }

        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('name');
    }

    private function calculateNewPrice(float $currentPrice, float $cost, string $type, float $value): float
    {
        return match ($type) {
            BulkPriceAdjustment::TYPE_PERCENTAGE_INCREASE => $currentPrice * (1 + ($value / 100)),
            BulkPriceAdjustment::TYPE_PERCENTAGE_DECREASE => $currentPrice * (1 - ($value / 100)),
            BulkPriceAdjustment::TYPE_FIXED_AMOUNT => $currentPrice + $value,
            BulkPriceAdjustment::TYPE_MARKUP_ON_COST => $cost * (1 + ($value / 100)),
            default => $currentPrice,
        };
    }

    private function applyRounding(float $price, string $rounding): float
    {
        return match ($rounding) {
            'integer' => ceil($price),
            'cents_99' => floor($price) + 0.99,
            'cents_50' => round($price * 2) / 2,
            default => round($price, 2),
        };
    }
}
