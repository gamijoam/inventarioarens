<?php

namespace App\Modules\InventoryCenter\Services;

use App\Modules\Fiscal\Models\FiscalTaxRate;
use App\Modules\InventoryCenter\Jobs\ApplyProductFiscalClassification;
use App\Modules\InventoryCenter\Models\ProductBulkOperation;
use App\Modules\InventoryCenter\Requests\InventoryCenterBulkActionRequest;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAudit;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Support\Performance\PerformanceProbe;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class InventoryCenterBulkActionService
{
    public function __construct(
        private readonly SyncCatalogOutboxService $syncCatalog,
        private readonly TenantManager $tenantManager,
    ) {}

    public function apply(array $data, ?int $userId): array
    {
        $productIds = array_values(array_unique(array_map('intval', $data['product_ids'])));
        $action = $data['action'];
        $payload = $data['payload'] ?? [];

        return PerformanceProbe::measure('InventoryCenter accion masiva total', function () use ($productIds, $action, $payload, $userId): array {
            return DB::transaction(function () use ($productIds, $action, $payload, $userId): array {
                $products = PerformanceProbe::measure(
                    'InventoryCenter accion masiva cargar productos',
                    fn () => Product::query()
                        ->whereIn('id', $productIds)
                        ->orderBy('name')
                        ->lockForUpdate()
                        ->get(),
                    300,
                    ['requested_count' => count($productIds), 'action' => $action]
                );

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
                    if ($action === InventoryCenterBulkActionRequest::ACTION_ASSIGN_FISCAL_TAX_RATE) {
                        $this->syncCatalog->productUpdated($product->refresh());
                    }

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
        }, 900, ['requested_count' => count($productIds), 'action' => $action]);
    }

    public function queueFiscalClassification(array $data, ?int $userId): ProductBulkOperation
    {
        $filters = $data['filters'] ?? [];
        $tenant = $this->tenantManager->require();
        $this->ensureFiscalRateIsActive((int) $data['payload']['fiscal_tax_rate_id']);
        $requestedCount = $this->filteredProductsQuery($filters)->count('products.id');
        $operation = ProductBulkOperation::create([
            'tenant_id' => $tenant->id,
            'user_id' => $userId,
            'action' => ProductBulkOperation::ACTION_ASSIGN_FISCAL_TAX_RATE,
            'filters' => $filters,
            'payload' => $data['payload'] ?? [],
            'status' => ProductBulkOperation::STATUS_PENDING,
            'requested_count' => $requestedCount,
        ]);

        ApplyProductFiscalClassification::dispatch($operation->id, $tenant->id);

        return $operation->refresh();
    }

    public function processFiscalOperation(ProductBulkOperation $operation): void
    {
        $operation->update([
            'status' => ProductBulkOperation::STATUS_RUNNING,
            'started_at' => now(),
            'error' => null,
        ]);

        try {
            $this->ensureFiscalRateIsActive((int) ($operation->payload['fiscal_tax_rate_id'] ?? 0));
            $this->filteredProductsQuery($operation->filters ?? [])
                ->orderBy('products.id')
                ->chunkById(100, function ($products) use ($operation): void {
                    $counts = DB::transaction(function () use ($products, $operation): array {
                        $updated = 0;
                        $skipped = 0;
                        $products = Product::query()
                            ->whereIn('id', $products->pluck('id'))
                            ->lockForUpdate()
                            ->get();

                        foreach ($products as $product) {
                            if (! $this->applyFiscalClassification($product, $operation->payload ?? [], $operation->user_id)) {
                                $skipped++;

                                continue;
                            }

                            $updated++;
                        }

                        return compact('updated', 'skipped');
                    });

                    $operation->increment('processed_count', $products->count());
                    $operation->increment('updated_count', $counts['updated']);
                    $operation->increment('skipped_count', $counts['skipped']);
                }, 'id');

            $operation->update([
                'status' => ProductBulkOperation::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $operation->update([
                'status' => ProductBulkOperation::STATUS_FAILED,
                'error' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function applyPriceListAction(Product $product, array $payload, bool $overwrite): array
    {
        return PerformanceProbe::measure('InventoryCenter accion masiva precio lista producto', function () use ($product, $payload, $overwrite): array {
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
        }, 150, ['product_id' => $product->id, 'price_list_id' => $payload['price_list_id'] ?? null]);
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
            InventoryCenterBulkActionRequest::ACTION_ASSIGN_FISCAL_TAX_RATE => $this->fiscalChangesFor($product, $payload),
            default => [],
        };
    }

    private function fiscalChangesFor(Product $product, array $payload): array
    {
        $targetId = (int) $payload['fiscal_tax_rate_id'];
        if (
            (! ($payload['overwrite_existing'] ?? false) && $product->fiscal_tax_rate_id !== null)
            || (int) $product->fiscal_tax_rate_id === $targetId
        ) {
            return [];
        }

        return ['fiscal_tax_rate_id' => $targetId];
    }

    private function applyFiscalClassification(Product $product, array $payload, ?int $userId): bool
    {
        $changes = $this->fiscalChangesFor($product, $payload);
        if ($changes === []) {
            return false;
        }

        $before = $product->only(array_keys($changes));
        $product->update($changes);
        $after = $product->refresh()->only(array_keys($changes));
        $this->recordAudit($product, ProductAudit::ACTION_UPDATED, $before, $after, $userId);
        $this->syncCatalog->productUpdated($product->refresh());

        return true;
    }

    private function ensureFiscalRateIsActive(int $taxRateId): void
    {
        if (FiscalTaxRate::query()->whereKey($taxRateId)->where('is_active', true)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'payload.fiscal_tax_rate_id' => 'El tratamiento fiscal seleccionado no está activo.',
        ]);
    }

    private function filteredProductsQuery(array $filters): Builder
    {
        $query = Product::query()->select('products.*');
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $query) use ($like): void {
                $query
                    ->whereRaw('LOWER(products.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(products.sku) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(products.barcode, \'\')) LIKE ?', [$like]);
            });
        }

        if (! empty($filters['tracking_type'])) {
            $query->where('products.tracking_type', $filters['tracking_type']);
        }

        match ($filters['active_status'] ?? 'active') {
            'all' => null,
            'inactive' => $query->where('products.is_active', false),
            default => $query->where('products.is_active', true),
        };

        foreach (['brand_id', 'category_id', 'tag_id'] as $filter) {
            if (! empty($filters[$filter])) {
                if ($filter === 'brand_id') {
                    $query->where('products.brand_id', (int) $filters[$filter]);
                } else {
                    $relation = $filter === 'category_id' ? 'categories' : 'tags';
                    $query->whereHas($relation, fn (Builder $relationQuery) => $relationQuery->whereKey((int) $filters[$filter]));
                }
            }
        }

        return $query;
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
