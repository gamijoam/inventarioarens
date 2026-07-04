<?php

namespace App\Modules\InventoryCenter\Services;

use App\Modules\InventoryCenter\Requests\InventoryCenterBulkActionRequest;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAudit;
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
