<?php

namespace App\Modules\InventoryCenter\Services;

use App\Modules\InventoryCenter\Requests\InventoryCenterBulkActionRequest;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAudit;
use App\Modules\Products\Models\ProductPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryCenterBulkActionService
{
    public function apply(array $data, ?int $userId): array
    {
        $productIds = array_values(array_unique(array_map('intval', $data['product_ids'])));
        $action = $data['action'];
        $payload = $data['payload'] ?? [];

        return DB::transaction(function () use ($productIds, $action, $payload, $userId): array {
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('name')
                ->lockForUpdate()
                ->get();

            $updated = [];
            $skipped = [];

            foreach ($products as $product) {
                if (in_array($action, [
                    InventoryCenterBulkActionRequest::ACTION_FILL_MISSING_PRICE_LIST,
                    InventoryCenterBulkActionRequest::ACTION_UPDATE_PRICE_LIST,
                ], true)) {
                    $result = $this->applyPriceListAction(
                        $product,
                        $payload,
                        $action === InventoryCenterBulkActionRequest::ACTION_UPDATE_PRICE_LIST
                    );
                    if (! $result['updated']) {
                        $skipped[] = [
                            'id' => $product->id,
                            'name' => $product->name,
                            'reason' => $result['reason'],
                        ];
                        continue;
                    }

                    $this->recordAudit(
                        $product,
                        ProductAudit::ACTION_UPDATED,
                        ['product_price' => $result['before']],
                        ['product_price' => $result['price']],
                        $userId
                    );

                    $updated[] = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                    ];
                    continue;
                }

                $changes = $this->changesFor($product, $action, $payload);

                if ($changes === []) {
                    $skipped[] = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'reason' => 'Sin cambios necesarios.',
                    ];
                    continue;
                }

                $before = $product->only(array_keys($changes));
                $product->update($changes);
                $after = $product->refresh()->only(array_keys($changes));

                $this->recordAudit($product, $this->auditAction($action), $before, $after, $userId);

                $updated[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                ];
            }

            return [
                'action' => $action,
                'requested_count' => count($productIds),
                'updated_count' => count($updated),
                'skipped_count' => count($skipped),
                'updated' => $updated,
                'skipped' => $skipped,
            ];
        });
    }

    private function applyPriceListAction(Product $product, array $payload, bool $overwrite): array
    {
        $priceListId = (int) $payload['price_list_id'];
        $productPrice = ProductPrice::query()
            ->where('product_id', $product->id)
            ->where('price_list_id', $priceListId)
            ->first();

        if ($productPrice && ! $overwrite) {
            return [
                'updated' => false,
                'reason' => 'El producto ya tiene precio para esa lista.',
            ];
        }

        $price = $this->calculatePrice($product, $payload);
        if ($price === null) {
            return [
                'updated' => false,
                'reason' => 'El producto no tiene precio base para calcular el precio.',
            ];
        }

        $attributes = [
            'price' => $price,
            'currency' => $payload['currency'],
            'exchange_rate_type_id' => $payload['sale_exchange_rate_type_id'] ?? $product->sale_exchange_rate_type_id,
            'is_active' => true,
        ];
        $before = $productPrice?->only(['price', 'currency', 'exchange_rate_type_id', 'is_active']);

        if ($productPrice) {
            $normalizedBefore = [
                'price' => round((float) $productPrice->price, 4),
                'currency' => $productPrice->currency,
                'exchange_rate_type_id' => $productPrice->exchange_rate_type_id,
                'is_active' => (bool) $productPrice->is_active,
            ];
            $normalizedAfter = [
                'price' => $attributes['price'],
                'currency' => $attributes['currency'],
                'exchange_rate_type_id' => $attributes['exchange_rate_type_id'],
                'is_active' => $attributes['is_active'],
            ];

            if ($normalizedBefore === $normalizedAfter) {
                return [
                    'updated' => false,
                    'reason' => 'Sin cambios necesarios.',
                ];
            }

            $productPrice->update($attributes);
        } else {
            ProductPrice::create([
                'product_id' => $product->id,
                'price_list_id' => $priceListId,
                ...$attributes,
            ]);
        }

        $after = [
            'price_list_id' => $priceListId,
            ...$attributes,
        ];

        return [
            'updated' => true,
            'before' => $before,
            'price' => $after,
        ];
    }

    private function calculatePrice(Product $product, array $payload): ?float
    {
        $strategy = $payload['strategy'];
        if ($strategy === InventoryCenterBulkActionRequest::PRICE_STRATEGY_FIXED_PRICE) {
            return round((float) $payload['price'], 4);
        }

        if ($product->base_price === null) {
            return null;
        }

        $basePrice = (float) $product->base_price;
        if ($strategy === InventoryCenterBulkActionRequest::PRICE_STRATEGY_BASE_PRICE) {
            return round($basePrice, 4);
        }

        if ($strategy === InventoryCenterBulkActionRequest::PRICE_STRATEGY_PERCENT_OVER_BASE) {
            return round($basePrice * (1 + (((float) $payload['percent']) / 100)), 4);
        }

        return null;
    }

    private function changesFor(Product $product, string $action, array $payload): array
    {
        return match ($action) {
            InventoryCenterBulkActionRequest::ACTION_ACTIVATE => $product->is_active ? [] : ['is_active' => true],
            InventoryCenterBulkActionRequest::ACTION_DEACTIVATE => ! $product->is_active ? [] : ['is_active' => false],
            InventoryCenterBulkActionRequest::ACTION_ASSIGN_WARRANTY_POLICY => (int) $product->warranty_policy_id === (int) $payload['warranty_policy_id']
                ? []
                : ['warranty_policy_id' => (int) $payload['warranty_policy_id']],
            InventoryCenterBulkActionRequest::ACTION_ASSIGN_EXCHANGE_RATE_TYPE => (int) $product->sale_exchange_rate_type_id === (int) $payload['sale_exchange_rate_type_id']
                ? []
                : ['sale_exchange_rate_type_id' => (int) $payload['sale_exchange_rate_type_id']],
            default => [],
        };
    }

    private function auditAction(string $action): string
    {
        return $action === InventoryCenterBulkActionRequest::ACTION_DEACTIVATE
            ? ProductAudit::ACTION_DEACTIVATED
            : ProductAudit::ACTION_UPDATED;
    }

    private function recordAudit(Product $product, string $action, array $before, array $after, ?int $userId): void
    {
        if (! Schema::hasTable('product_audits')) {
            return;
        }

        ProductAudit::create([
            'product_id' => $product->id,
            'action' => $action,
            'changes' => [
                'before' => $before,
                'after' => $after,
            ],
            'created_by' => $userId,
        ]);
    }
}
