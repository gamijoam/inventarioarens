<?php

namespace App\Modules\Products\Services;

use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Products\Models\Tag;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Sync\Services\SyncOutboxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warranties\Models\WarrantyPolicy;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SharedCatalogPropagationService
{
    public function __construct(
        private readonly SyncOutboxService $outbox,
        private readonly TenantManager $tenants,
    ) {}

    /**
     * Emite el evento de sincronizacion en el outbox del spinoff para que
     * el nodo local de esa empresa baje el cambio del catalogo compartido.
     * La propagacion del grupo a las hijas escribe directo en el VPS; sin
     * este evento, los nodos locales de las hijas nunca reciben el cambio.
     */
    private function recordSpinoffEvent(Tenant $spinoff, string $eventType, string $aggregateType, int $aggregateId, array $payload): void
    {
        $previous = $this->tenants->current();
        $this->tenants->set($spinoff);

        try {
            $this->outbox->record(
                eventType: $eventType,
                aggregateType: $aggregateType,
                aggregateId: $aggregateId,
                payload: $payload,
            );
        } finally {
            if ($previous) {
                $this->tenants->set($previous);
            } else {
                $this->tenants->clear();
            }
        }
    }

    public function propagateMaster(Product $master): void
    {
        if (! $master->isCatalogMaster()) {
            return;
        }

        $spinoffs = $this->spinoffsFor($master->tenant_id);

        DB::transaction(function () use ($master, $spinoffs): void {
            foreach ($spinoffs as $spinoff) {
                $this->ensureProductCopyFor($master, $spinoff);
            }
        });
    }

    /**
     * Propaga a cada spinoff los catalogos referenciados por un producto
     * maestro (brand, categorias, tags) si la copia local no existe aun.
     * Esto permite que cuando un spinoff abre la copia del producto,
     * las categorias/tags ya existan localmente para relacionar.
     */
    public function propagateReferencedCatalogForMaster(Product $master): void
    {
        if (! $master->isCatalogMaster()) {
            return;
        }

        $master->loadMissing(['brand', 'categories', 'tags', 'warrantyPolicy', 'saleExchangeRateType']);

        DB::transaction(function () use ($master): void {
            $spinoffs = $this->spinoffsFor($master->tenant_id);

            foreach ($spinoffs as $spinoff) {
                if ($master->brand) {
                    $this->ensureBrandCopyFor($master->brand, $spinoff);
                }

                if ($master->warrantyPolicy) {
                    $this->ensureWarrantyPolicyCopyFor($master->warrantyPolicy, $spinoff);
                }

                if ($master->saleExchangeRateType) {
                    $copy = $this->ensureExchangeRateTypeCopyFor($master->saleExchangeRateType, $spinoff);
                    $this->ensureExchangeRateCopyFor(
                        $master->saleExchangeRateType->rates()->withoutGlobalScopes()->first() ?? new ExchangeRate,
                        $spinoff,
                        $copy->id,
                    );
                }

                if ($master->relationLoaded('categories') && $master->categories->isNotEmpty()) {
                    $this->propagateSpecificCategoriesToSpinoff($master, $spinoff);
                }

                if ($master->relationLoaded('tags') && $master->tags->isNotEmpty()) {
                    foreach ($master->tags as $tag) {
                        $this->ensureTagCopyFor($tag, $spinoff);
                    }
                }
            }
        });
    }

    /**
     * Propaga las categorias referenciadas por un producto maestro,
     * incluyendo los ancestros necesarios para mantener la jerarquia
     * en el spinoff.
     */
    private function propagateSpecificCategoriesToSpinoff(Product $master, Tenant $spinoff): void
    {
        $categoryIds = $master->categories->pluck('id')->all();
        if ($categoryIds === []) {
            return;
        }

        $masterCategories = Category::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $categoryIds)
            ->where('tenant_id', $master->tenant_id)
            ->get();

        $ancestorIds = [];
        foreach ($masterCategories as $cat) {
            $cursor = $cat->parent_id;
            while ($cursor !== null && ! in_array($cursor, $ancestorIds, true)) {
                $ancestorIds[] = $cursor;
                $parent = Category::query()
                    ->withoutGlobalScopes()
                    ->where('id', $cursor)
                    ->where('tenant_id', $master->tenant_id)
                    ->first(['parent_id']);
                $cursor = $parent?->parent_id;
            }
        }

        $allIds = array_unique(array_merge($categoryIds, $ancestorIds));
        $ordered = Category::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $allIds)
            ->where('tenant_id', $master->tenant_id)
            ->orderByRaw('CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('parent_id')
            ->orderBy('id')
            ->get();

        $parentMap = [];
        foreach ($ordered as $category) {
            $copy = $this->upsertCategoryCopy($category, $spinoff, $parentMap);
            $parentMap[$category->id] = $copy->id;
        }
    }

    /**
     * Propaga UNA SOLA categoria (con sus ancestros) al spinoff. Pensado
     * para el hook del modelo Category: cuando un admin del grupo crea
     * una nueva categoria, se clona en cada spinoff junto con los
     * ancestros para mantener la jerarquia.
     */
    public function propagateSingleCategoryToSpinoff(Category $master, Tenant $spinoff): void
    {
        if ((int) $master->tenant_id === (int) $spinoff->id) {
            return;
        }

        DB::transaction(function () use ($master, $spinoff): void {
            $ancestors = collect();
            $cursor = $master->parent_id;
            while ($cursor !== null) {
                $parent = Category::query()
                    ->withoutGlobalScopes()
                    ->where('id', $cursor)
                    ->where('tenant_id', $master->tenant_id)
                    ->first();
                if (! $parent) {
                    break;
                }
                $ancestors->prepend($parent);
                $cursor = $parent->parent_id;
            }

            $parentMap = [];
            foreach ($ancestors as $ancestor) {
                $copy = $this->upsertCategoryCopy($ancestor, $spinoff, $parentMap);
                $parentMap[$ancestor->id] = $copy->id;
            }

            $this->upsertCategoryCopy($master, $spinoff, $parentMap);
        });
    }

    public function syncMasterFieldsToCopies(Product $master): void
    {
        if (! $master->isCatalogMaster()) {
            return;
        }

        $masterFields = array_unique(array_merge(Product::MASTER_FIELDS, [
            'unit_of_measure',
            'track_stock',
        ]));

        $payload = collect($masterFields)
            ->mapWithKeys(fn (string $field): array => [$field => $master->{$field}])
            ->all();

        DB::transaction(function () use ($master, $payload): void {
            $master->localCopies()->each(function (Product $copy) use ($payload, $master): void {
                $copy->fill($payload);
                $copy->is_catalog_active = (bool) $master->is_catalog_active;
                DB::table('products')
                    ->where('id', $copy->id)
                    ->where('tenant_id', $copy->tenant_id)
                    ->update(array_merge($payload, [
                        'is_catalog_active' => (bool) $master->is_catalog_active,
                        'updated_at' => now(),
                    ]));
            });
        });
    }

    public function deactivateCopiesForMaster(Product $master): void
    {
        if (! $master->isCatalogMaster()) {
            return;
        }

        DB::transaction(function () use ($master): void {
            $master->localCopies()->each(function (Product $copy): void {
                if ($copy->is_catalog_active === false) {
                    return;
                }
                DB::table('products')
                    ->where('id', $copy->id)
                    ->where('tenant_id', $copy->tenant_id)
                    ->update([
                        'is_catalog_active' => false,
                        'is_active' => false,
                        'updated_at' => now(),
                    ]);
            });
        });
    }

    /**
     * Propaga TODO el catalogo del grupo a un spinoff: productos maestros,
     * marcas, categorias (con jerarquia), tags, listas de precios, metodos
     * de pago, tipos de tasa y politicas de garantia.
     *
     * Pensado para bootstrap al crear un spinoff o para regenerar copias
     * tras cambios estructurales (ej. migracion de backfill).
     */
    public function propagateAllToSpinoff(Tenant $group, Tenant $spinoff): void
    {
        if ((int) $group->id === (int) $spinoff->id) {
            return;
        }

        DB::transaction(function () use ($group, $spinoff): void {
            $warrantyPolicies = WarrantyPolicy::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $group->id)
                ->orderBy('id')
                ->get();
            foreach ($warrantyPolicies as $policy) {
                $this->ensureWarrantyPolicyCopyFor($policy, $spinoff);
            }

            $exchangeRateTypes = ExchangeRateType::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $group->id)
                ->orderBy('id')
                ->get();
            foreach ($exchangeRateTypes as $rateType) {
                $copy = $this->ensureExchangeRateTypeCopyFor($rateType, $spinoff);
                $rates = ExchangeRate::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $group->id)
                    ->where('exchange_rate_type_id', $rateType->id)
                    ->orderBy('effective_at')
                    ->get();
                foreach ($rates as $rate) {
                    $this->ensureExchangeRateCopyFor($rate, $spinoff, $copy->id);
                }
            }

            $brands = Brand::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $group->id)
                ->orderBy('id')
                ->get();
            foreach ($brands as $brand) {
                $this->ensureBrandCopyFor($brand, $spinoff);
            }

            $this->propagateCategoriesTopological($group, $spinoff);

            $tags = Tag::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $group->id)
                ->orderBy('id')
                ->get();
            foreach ($tags as $tag) {
                $this->ensureTagCopyFor($tag, $spinoff);
            }

            $paymentMethods = PaymentMethod::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $group->id)
                ->orderBy('id')
                ->get();
            foreach ($paymentMethods as $method) {
                $this->ensurePaymentMethodCopyFor($method, $spinoff);
            }

            $priceLists = PriceList::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $group->id)
                ->orderBy('id')
                ->get();
            foreach ($priceLists as $list) {
                $copy = $this->ensurePriceListCopyFor($list, $spinoff);
                $this->syncPriceListPaymentMethods($list, $copy, $spinoff);
            }

            $masters = Product::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $group->id)
                ->where('is_catalog_master', true)
                ->orderBy('id')
                ->get();
            foreach ($masters as $master) {
                $this->ensureProductCopyFor($master, $spinoff);
            }

            foreach ($priceLists as $list) {
                $copy = $this->ensurePriceListCopyFor($list, $spinoff);
                $this->syncPriceListProductPrices($list, $copy, $spinoff);
            }

            $suppliers = Supplier::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $group->id)
                ->orderBy('id')
                ->get();
            foreach ($suppliers as $supplier) {
                $this->ensureSupplierCopyFor($supplier, $spinoff);
            }

            $this->deactivateOrphanedSharedCopies($group, $spinoff);
        });
    }

    public function syncProductPriceCopyFor(ProductPrice $master, Tenant $spinoff): ?ProductPrice
    {
        $master->loadMissing(['product', 'priceList', 'exchangeRateType']);

        if (! $master->product?->isCatalogMaster() || ! $master->priceList) {
            return null;
        }

        $copyProduct = Product::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $master->product_id)
            ->first();
        $copyList = PriceList::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('code', $master->priceList->code)
            ->first();

        if (! $copyProduct || ! $copyList) {
            return null;
        }

        $localRateTypeId = null;
        if ($master->exchangeRateType) {
            $localRateTypeId = $this->ensureExchangeRateTypeCopyFor(
                $master->exchangeRateType,
                $spinoff,
            )->id;
        }

        $existing = ProductPrice::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('product_id', $copyProduct->id)
            ->where('price_list_id', $copyList->id)
            ->first();

        $payload = [
            'tenant_id' => $spinoff->id,
            'product_id' => $copyProduct->id,
            'price_list_id' => $copyList->id,
            'price' => $master->price,
            'currency' => $master->currency,
            'exchange_rate_type_id' => $localRateTypeId,
            'is_active' => $master->is_active,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('product_prices')->where('id', $existing->id)->update($payload);

            return $existing->fresh();
        }

        $payload['created_at'] = now();
        DB::table('product_prices')->insert($payload);

        return ProductPrice::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('product_id', $copyProduct->id)
            ->where('price_list_id', $copyList->id)
            ->first();
    }

    public function ensureProductCopyFor(Product $master, Tenant $spinoff): Product
    {
        if ((int) $master->tenant_id === (int) $spinoff->id) {
            return $master;
        }

        $copy = Product::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $master->id)
            ->first();

        if ($copy) {
            $updated = $this->refreshProductForeignKeys($copy, $master, $spinoff);
            $this->recordProductCopyEvent($updated, $master, $spinoff, false);

            return $updated;
        }

        // Aseguramos que las relaciones que vamos a copiar esten cargadas.
        $master->loadMissing(['brand', 'warrantyPolicy', 'saleExchangeRateType', 'categories', 'tags']);

        // Aseguramos que los catalogos referenciados existan localmente
        // antes de insertar el producto (FK compuesta tenant_id+xxx_id).
        $master->loadMissing(['brand', 'warrantyPolicy', 'saleExchangeRateType']);
        if ($master->brand) {
            $this->ensureBrandCopyFor($master->brand, $spinoff);
        }
        if ($master->warrantyPolicy) {
            $this->ensureWarrantyPolicyCopyFor($master->warrantyPolicy, $spinoff);
        }
        if ($master->saleExchangeRateType) {
            $this->ensureExchangeRateTypeCopyFor($master->saleExchangeRateType, $spinoff);
        }

        $localBrandId = $master->brand
            ? Brand::query()->withoutGlobalScopes()->where('tenant_id', $spinoff->id)->where('slug', $master->brand->slug)->value('id')
            : null;
        $localWarrantyId = $master->warrantyPolicy
            ? WarrantyPolicy::query()->withoutGlobalScopes()->where('tenant_id', $spinoff->id)->where('name', $master->warrantyPolicy->name)->value('id')
            : null;
        $localExchangeRateTypeId = $master->saleExchangeRateType
            ? ExchangeRateType::query()->withoutGlobalScopes()->where('tenant_id', $spinoff->id)->where('code', $master->saleExchangeRateType->code)->value('id')
            : null;

        $attributes = collect(Product::MASTER_FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => $master->{$field}])
            ->all();

        $attributes['tenant_id'] = $spinoff->id;
        $attributes['catalog_product_id'] = $master->id;
        $attributes['is_catalog_master'] = false;
        $attributes['is_catalog_active'] = (bool) $master->is_catalog_active;
        $attributes['is_active'] = true;
        $attributes['unit_of_measure'] = $master->unit_of_measure ?: Product::UNIT_UNIT;
        $attributes['track_stock'] = (bool) ($master->track_stock ?? true);
        $attributes['brand_id'] = $localBrandId;
        $attributes['warranty_policy_id'] = $localWarrantyId;
        $attributes['sale_exchange_rate_type_id'] = $localExchangeRateTypeId;
        $attributes['created_at'] = now();
        $attributes['updated_at'] = now();

        DB::table('products')->insert(
            collect($attributes)
                ->reject(fn ($_, string $key): bool => $key === 'id')
                ->all(),
        );

        $copy = Product::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('catalog_product_id', $master->id)
            ->first();

        if (! $copy) {
            throw new \RuntimeException(sprintf(
                'Failed to create product copy for tenant %d (master_id=%d)',
                $spinoff->id,
                $master->id,
            ));
        }

        if ($master->relationLoaded('categories')) {
            $masterCategoryIds = $master->categories->pluck('id')->all();
            $spinoffCategoryIds = $this->translateForeignIdsToLocalIds(
                $masterCategoryIds,
                'categories',
                $master->tenant_id,
                $spinoff->id,
            );
            if ($spinoffCategoryIds !== []) {
                $copy->categories()->syncWithPivotValues($spinoffCategoryIds, ['tenant_id' => $spinoff->id]);
            }
        }

        if ($master->relationLoaded('tags')) {
            $masterTagIds = $master->tags->pluck('id')->all();
            $spinoffTagIds = $this->translateForeignIdsToLocalIds(
                $masterTagIds,
                'tags',
                $master->tenant_id,
                $spinoff->id,
            );
            if ($spinoffTagIds !== []) {
                $copy->tags()->syncWithPivotValues($spinoffTagIds, ['tenant_id' => $spinoff->id]);
            }
        }

        $this->recordProductCopyEvent($copy, $master, $spinoff, true);

        return $copy;
    }

    private function recordProductCopyEvent(Product $copy, Product $master, Tenant $spinoff, bool $created): void
    {
        $master->loadMissing(['saleExchangeRateType', 'brand', 'categories', 'tags', 'warrantyPolicy']);

        $this->recordSpinoffEvent(
            $spinoff,
            $created ? 'product.created' : 'product.updated',
            'product',
            (int) $copy->id,
            [
                'sku' => $master->sku,
                'name' => $master->name,
                'barcode' => $master->barcode,
                'description' => $master->description,
                'long_description' => $master->long_description,
                'tracking_type' => $master->tracking_type,
                'unit_of_measure' => $master->unit_of_measure,
                'track_stock' => (bool) $master->track_stock,
                'base_price' => $master->base_price === null ? null : (string) $master->base_price,
                'profit_margin' => $master->profit_margin === null ? null : (string) $master->profit_margin,
                'pricing_mode' => $master->pricing_mode ?? Product::PRICING_AUTOMATIC,
                'sale_currency' => $master->sale_currency,
                'sale_exchange_rate_type_code' => $master->saleExchangeRateType?->code,
                'image_url' => $master->image_url,
                'brand_slug' => $master->brand?->slug,
                'category_slugs' => $master->categories->pluck('slug')->values()->all(),
                'tag_slugs' => $master->tags->pluck('slug')->values()->all(),
                'warranty_policy_id' => $master->warranty_policy_id,
                'warranty_policy_name' => $master->warrantyPolicy?->name,
                'warranty_policy_duration_days' => $master->warrantyPolicy?->duration_days,
                'warranty_policy_coverage_type' => $master->warrantyPolicy?->coverage_type,
                'warranty_policy_conditions' => $master->warrantyPolicy?->conditions,
                'warranty_policy_is_active' => $master->warrantyPolicy ? (bool) $master->warrantyPolicy->is_active : null,
                'min_stock' => $master->min_stock === null ? null : (string) $master->min_stock,
                'max_stock' => $master->max_stock === null ? null : (string) $master->max_stock,
                'reorder_quantity' => $master->reorder_quantity === null ? null : (string) $master->reorder_quantity,
                'is_catalog_active' => (bool) ($master->is_catalog_active ?? true),
                'is_active' => (bool) $master->is_active,
            ],
        );
    }

    /**
     * Si la copia ya existia, refresca las FKs del producto con los
     * IDs locales del spinoff (pueden haber cambiado si el catalogo se
     * re-propago despues).
     */
    private function refreshProductForeignKeys(Product $copy, Product $master, Tenant $spinoff): Product
    {
        $master->loadMissing(['brand', 'warrantyPolicy', 'saleExchangeRateType']);

        $updates = [];

        foreach (Product::MASTER_FIELDS as $field) {
            if (in_array($field, ['brand_id', 'warranty_policy_id', 'sale_exchange_rate_type_id'], true)) {
                continue;
            }

            $masterValue = $master->getAttribute($field);
            $copyValue = $copy->getAttribute($field);

            if ((string) $masterValue !== (string) $copyValue) {
                $updates[$field] = $masterValue;
            }
        }

        if ($master->brand) {
            $localId = Brand::query()->withoutGlobalScopes()->where('tenant_id', $spinoff->id)->where('slug', $master->brand->slug)->value('id');
            if ($localId && (int) $copy->brand_id !== (int) $localId) {
                $updates['brand_id'] = $localId;
            }
        }
        if ($master->warrantyPolicy) {
            $localId = WarrantyPolicy::query()->withoutGlobalScopes()->where('tenant_id', $spinoff->id)->where('name', $master->warrantyPolicy->name)->value('id');
            if ($localId && (int) $copy->warranty_policy_id !== (int) $localId) {
                $updates['warranty_policy_id'] = $localId;
            }
        }
        if ($master->saleExchangeRateType) {
            $localId = ExchangeRateType::query()->withoutGlobalScopes()->where('tenant_id', $spinoff->id)->where('code', $master->saleExchangeRateType->code)->value('id');
            if ($localId && (int) $copy->sale_exchange_rate_type_id !== (int) $localId) {
                $updates['sale_exchange_rate_type_id'] = $localId;
            }
        }

        if ($updates !== []) {
            $updates['updated_at'] = now();
            DB::table('products')
                ->where('id', $copy->id)
                ->update($updates);

            return $copy->fresh();
        }

        return $copy;
    }

    public function ensureBrandCopyFor(Brand $master, Tenant $spinoff): Brand
    {
        return $this->upsertCopy($master, $spinoff, [
            'name' => null,
            'slug' => null,
            'description' => null,
            'is_active' => null,
        ]);
    }

    public function ensureTagCopyFor(Tag $master, Tenant $spinoff): Tag
    {
        return $this->upsertCopy($master, $spinoff, [
            'name' => null,
            'slug' => null,
            'color' => null,
        ]);
    }

    public function ensureWarrantyPolicyCopyFor(WarrantyPolicy $master, Tenant $spinoff): WarrantyPolicy
    {
        return $this->upsertCopy($master, $spinoff, [
            'name' => null,
            'duration_days' => null,
            'coverage_type' => null,
            'conditions' => null,
            'is_active' => null,
        ]);
    }

    public function ensureExchangeRateTypeCopyFor(ExchangeRateType $master, Tenant $spinoff): ExchangeRateType
    {
        return $this->upsertCopy($master, $spinoff, [
            'code' => null,
            'name' => null,
            'is_default' => null,
            'is_active' => null,
        ]);
    }

    public function ensureExchangeRateCopyFor(ExchangeRate $master, Tenant $spinoff, int $newRateTypeId): ExchangeRate
    {
        $existing = ExchangeRate::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('exchange_rate_type_id', $newRateTypeId)
            ->where('effective_at', $master->effective_at)
            ->first();

        $payload = [
            'tenant_id' => $spinoff->id,
            'exchange_rate_type_id' => $newRateTypeId,
            'base_currency' => $master->base_currency,
            'quote_currency' => $master->quote_currency,
            'rate' => $master->rate,
            'effective_at' => $master->effective_at,
            'source' => $master->source,
            'is_active' => $master->is_active,
        ];

        if ($master->is_active) {
            ExchangeRate::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $spinoff->id)
                ->where('exchange_rate_type_id', $newRateTypeId)
                ->where('base_currency', $master->base_currency)
                ->where('quote_currency', $master->quote_currency)
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
        }

        if ($existing) {
            $payload['updated_at'] = now();
            DB::table('exchange_rates')
                ->where('id', $existing->id)
                ->update($payload);

            $copy = $existing->fresh();
        } else {
            $payload['created_at'] = now();
            $payload['updated_at'] = now();

            DB::table('exchange_rates')->insert($payload);

            $copy = ExchangeRate::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $spinoff->id)
                ->where('exchange_rate_type_id', $newRateTypeId)
                ->where('effective_at', $master->effective_at)
                ->first();
        }

        $typeCode = $master->relationLoaded('type') ? $master->type?->code : null;
        $typeCode ??= ExchangeRateType::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $master->tenant_id)
            ->where('id', $master->exchange_rate_type_id)
            ->value('code');

        $this->recordSpinoffEvent(
            $spinoff,
            $existing ? 'exchange_rate.updated' : 'exchange_rate.created',
            'exchange_rate',
            (int) $copy->id,
            [
                'exchange_rate_type_code' => $typeCode,
                'base_currency' => $master->base_currency,
                'quote_currency' => $master->quote_currency,
                'rate' => (string) $master->rate,
                'effective_at' => $master->effective_at?->toISOString(),
                'source' => $master->source,
                'is_active' => (bool) $master->is_active,
            ],
        );

        return $copy;
    }

    public function ensurePaymentMethodCopyFor(PaymentMethod $master, Tenant $spinoff): PaymentMethod
    {
        return $this->upsertCopy($master, $spinoff, [
            'code' => null,
            'name' => null,
            'method' => null,
            'currency_mode' => null,
            'requires_reference' => null,
            'is_active' => null,
            'sort_order' => null,
        ]);
    }

    public function ensurePriceListCopyFor(PriceList $master, Tenant $spinoff): PriceList
    {
        $master->loadMissing('paymentExchangeRateType');
        $localPaymentRateTypeId = $master->paymentExchangeRateType
            ? $this->ensureExchangeRateTypeCopyFor($master->paymentExchangeRateType, $spinoff)->id
            : null;

        $copy = $this->upsertCopy($master, $spinoff, [
            'name' => null,
            'code' => null,
            'description' => null,
            'markup_percentage' => null,
            'payment_exchange_rate_type_id' => null,
            'is_default' => null,
            'is_active' => null,
            'sort_order' => null,
        ], [
            'payment_exchange_rate_type_id' => $localPaymentRateTypeId,
        ]);

        return $copy;
    }

    public function propagatePriceListConfiguration(PriceList $master): void
    {
        foreach ($this->spinoffsFor((int) $master->tenant_id) as $spinoff) {
            $copy = $this->ensurePriceListCopyFor($master, $spinoff);
            $this->syncPriceListPaymentMethods($master, $copy, $spinoff);
        }
    }

    /**
     * Clona la lista de precios + replica sus asociaciones a metodos de
     * pago usando los IDs locales del spinoff.
     */
    public function syncPriceListPaymentMethods(PriceList $master, PriceList $copy, Tenant $spinoff): void
    {
        $masterMethodIds = DB::table('price_list_payment_method')
            ->where('price_list_id', $master->id)
            ->where('tenant_id', $master->tenant_id)
            ->pluck('payment_method_id')
            ->all();

        DB::table('price_list_payment_method')
            ->where('tenant_id', $spinoff->id)
            ->where('price_list_id', $copy->id)
            ->delete();

        if ($masterMethodIds === []) {
            return;
        }

        $spinoffMethodIds = $this->translateForeignIdsToLocalIds(
            $masterMethodIds,
            'payment_methods',
            $master->tenant_id,
            $spinoff->id,
        );

        $rows = [];
        $now = now();
        foreach ($spinoffMethodIds as $methodId) {
            $rows[] = [
                'price_list_id' => $copy->id,
                'payment_method_id' => $methodId,
                'tenant_id' => $spinoff->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('price_list_payment_method')->insert($rows);
    }

    public function syncPriceListProductPrices(PriceList $master, PriceList $copy, Tenant $spinoff): void
    {
        $masterPrices = ProductPrice::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $master->tenant_id)
            ->where('price_list_id', $master->id)
            ->with(['product', 'exchangeRateType'])
            ->get();

        $syncedIds = [];
        foreach ($masterPrices as $masterPrice) {
            $local = $this->syncProductPriceCopyFor($masterPrice, $spinoff);
            if ($local) {
                $syncedIds[] = $local->id;
            }
        }

        $query = ProductPrice::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('price_list_id', $copy->id);

        if ($syncedIds !== []) {
            $query->whereNotIn('id', $syncedIds);
        }

        $query->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function ensureSupplierCopyFor(Supplier $master, Tenant $spinoff): Supplier
    {
        $query = Supplier::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id);

        if ($master->document_type !== null && $master->document_number !== null) {
            $query->where('document_type', $master->document_type)
                ->where('document_number', $master->document_number);
        } else {
            $query->where('name', $master->name);
        }

        $existing = $query->first();
        $payload = [
            'tenant_id' => $spinoff->id,
            'name' => $master->name,
            'document_type' => $master->document_type,
            'document_number' => $master->document_number,
            'phone' => $master->phone,
            'email' => $master->email,
            'fiscal_address' => $master->fiscal_address,
            'notes' => $master->notes,
            'is_active' => $master->is_active,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('suppliers')->where('id', $existing->id)->update($payload);

            return $existing->fresh();
        }

        $payload['created_at'] = now();
        DB::table('suppliers')->insert($payload);

        return Supplier::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->when(
                $master->document_type !== null && $master->document_number !== null,
                fn ($q) => $q->where('document_type', $master->document_type)->where('document_number', $master->document_number),
                fn ($q) => $q->where('name', $master->name),
            )
            ->firstOrFail();
    }

    private function deactivateOrphanedSharedCopies(Tenant $group, Tenant $spinoff): void
    {
        $this->deactivateOrphans('price_lists', $group, $spinoff, 'code');
        $this->deactivateOrphans('exchange_rate_types', $group, $spinoff, 'code');
        $this->deactivateOrphans('warranty_policies', $group, $spinoff, 'name');

        $masterSuppliers = Supplier::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $group->id)
            ->get(['document_type', 'document_number', 'name']);
        $childSuppliers = Supplier::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->get();

        foreach ($childSuppliers as $supplier) {
            $exists = $masterSuppliers->contains(function (Supplier $master) use ($supplier): bool {
                if ($master->document_type !== null && $master->document_number !== null) {
                    return $master->document_type === $supplier->document_type
                        && $master->document_number === $supplier->document_number;
                }

                return $master->name === $supplier->name;
            });

            if (! $exists) {
                DB::table('suppliers')->where('id', $supplier->id)->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function deactivateOrphans(string $table, Tenant $group, Tenant $spinoff, string $key): void
    {
        $masterValues = DB::table($table)
            ->where('tenant_id', $group->id)
            ->pluck($key);

        $query = DB::table($table)
            ->where('tenant_id', $spinoff->id);

        if ($masterValues->isNotEmpty()) {
            $query->whereNotIn($key, $masterValues->all());
        }

        $query->update(['is_active' => false, 'updated_at' => now()]);
    }

    private function propagateCategoriesTopological(Tenant $group, Tenant $spinoff): void
    {
        $all = Category::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $group->id)
            ->orderBy('parent_id')
            ->orderBy('id')
            ->get();

        $processed = [];
        $remaining = $all;

        while ($remaining->isNotEmpty()) {
            $progress = false;
            $batch = $remaining->filter(function (Category $cat) use ($processed) {
                $parent = $cat->parent_id;

                return $parent === null || isset($processed[$parent]);
            });

            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $category) {
                $copy = $this->upsertCategoryCopy($category, $spinoff, $processed);
                $processed[$category->id] = $copy->id;
                $progress = true;
            }

            $remaining = $remaining->reject(fn (Category $cat) => isset($processed[$cat->id]));
        }
    }

    private function upsertCategoryCopy(Category $master, Tenant $spinoff, array $parentMap): Category
    {
        $newParentId = null;
        if ($master->parent_id !== null && isset($parentMap[$master->parent_id])) {
            $newParentId = $parentMap[$master->parent_id];
        }

        $existing = Category::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('slug', $master->slug)
            ->first();

        if ($existing) {
            $existing->fill([
                'name' => $master->name,
                'description' => $master->description,
                'sort_order' => $master->sort_order,
                'is_active' => $master->is_active,
                'parent_id' => $newParentId,
            ])->save();

            return $existing->fresh();
        }

        $attributes = [
            'parent_id' => $newParentId,
            'name' => $master->name,
            'slug' => $master->slug,
            'description' => $master->description,
            'sort_order' => $master->sort_order ?? 0,
            'is_active' => $master->is_active,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $attributes['tenant_id'] = $spinoff->id;

        DB::table('categories')->insert($attributes);

        return Category::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where('slug', $master->slug)
            ->first();
    }

    /**
     * Inserta (si no existe) o actualiza (si existe) una copia local
     * del catalogo del grupo. La copia se identifica por `code` o `slug`
     * segun el modelo.
     *
     * @param  array<string, mixed>  $fields  Campos a copiar como
     *                                        `['campo' => valorOverride?, ...]`. Si el valor es null,
     *                                        se toma de $master->{$campo}.
     * @param  array<string, mixed>  $overrides  Valores fijos para campos
     *                                           especificos (ej. para reemplazar una FK por su contraparte
     *                                           local). Tienen prioridad sobre $master.
     */
    private function upsertCopy(object $master, Tenant $spinoff, array $fields, array $overrides = [], ?string $modelClass = null): object
    {
        $modelClass ??= get_class($master);

        $table = (new $modelClass)->getTable();
        $matchKey = $this->matchKeyFor($modelClass);
        $matchValue = $master->{$matchKey};

        $existing = $modelClass::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where($matchKey, $matchValue)
            ->first();

        $payload = ['tenant_id' => $spinoff->id];
        foreach ($fields as $field => $_) {
            if (array_key_exists($field, $overrides)) {
                $payload[$field] = $overrides[$field];
            } else {
                $payload[$field] = $master->{$field} ?? null;
            }
        }

        if ($existing) {
            $payload['updated_at'] = now();
            DB::table($table)
                ->where('id', $existing->id)
                ->where('tenant_id', $spinoff->id)
                ->update($payload);

            return $existing->fresh();
        }

        $payload['created_at'] = now();
        $payload['updated_at'] = now();

        DB::table($table)->insert($payload);

        $copy = $modelClass::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $spinoff->id)
            ->where($matchKey, $matchValue)
            ->first();

        if (! $copy) {
            throw new \RuntimeException(sprintf(
                'Failed to create %s copy for tenant %d (matchKey=%s, matchValue=%s)',
                $modelClass,
                $spinoff->id,
                $matchKey,
                (string) $matchValue,
            ));
        }

        return $copy;
    }

    /**
     * Traduce IDs de catalogos del tenant origen a IDs locales del
     * spinoff, usando `code` o `slug` como llave de correspondencia.
     * Se usa para replicar relaciones (categorias, tags, metodos de pago)
     * sin arrastrar FKs rotas por scope.
     */
    private function translateForeignIdsToLocalIds(array $foreignIds, string $table, int $fromTenantId, int $toTenantId): array
    {
        if ($foreignIds === []) {
            return [];
        }

        $matchKey = $this->matchKeyForTable($table);

        $foreignValues = DB::table($table)
            ->whereIn('id', $foreignIds)
            ->where('tenant_id', $fromTenantId)
            ->pluck($matchKey, 'id');

        if ($foreignValues->isEmpty()) {
            return [];
        }

        $localIds = DB::table($table)
            ->where('tenant_id', $toTenantId)
            ->whereIn($matchKey, $foreignValues->values()->all())
            ->pluck('id', $matchKey);

        $mapped = [];
        foreach ($foreignValues as $foreignId => $matchValue) {
            if ($localIds->has($matchValue)) {
                $mapped[] = $localIds[$matchValue];
            }
        }

        return $mapped;
    }

    private function matchKeyFor(string $modelClass): string
    {
        return match ($modelClass) {
            Brand::class => 'slug',
            Tag::class => 'slug',
            Category::class => 'slug',
            PriceList::class => 'code',
            PaymentMethod::class => 'code',
            ExchangeRateType::class => 'code',
            WarrantyPolicy::class => 'name',
            ExchangeRate::class => 'id',
            default => 'id',
        };
    }

    private function matchKeyForTable(string $table): string
    {
        return match ($table) {
            'brands' => 'slug',
            'tags' => 'slug',
            'categories' => 'slug',
            'price_lists' => 'code',
            'payment_methods' => 'code',
            'exchange_rate_types' => 'code',
            'warranty_policies' => 'name',
            'exchange_rates' => 'id',
            default => 'id',
        };
    }

    private function spinoffsFor(int $groupTenantId): Collection
    {
        return Tenant::query()
            ->where('parent_id', $groupTenantId)
            ->where('is_group', false)
            ->get();
    }
}
