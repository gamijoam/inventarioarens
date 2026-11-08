<?php

namespace App\Modules\DataImport\Importers;

use App\Models\User;
use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\ProductEntries\Services\ProductEntryService;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Products\Models\Tag;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductImporter extends BaseImporter
{
    /**
     * Inyectamos SharedCatalogPropagationService para que cada producto
     * creado via importacion se replique automaticamente a los spinoffs
     * del grupo. Antes solo se creaba en el grupo, dejando los spinoffs
     * vacios hasta que se ejecutara manualmente el comando
     * `php artisan catalog:resync-stock` o se creara el producto
     * via la UI (que si llamaba a `propagateMaster`).
     */
    public function __construct(
        private readonly SharedCatalogPropagationService $propagation,
    ) {}

    public function entity(): string
    {
        return 'products';
    }

    public function headers(): array
    {
        return [
            'sku',
            'name',
            'barcode',
            'description',
            'category',
            'categoria',
            'categorias',
            'category_slugs',
            'category_slug',
            'categoria_slug',
            'rubro',
            'departamento',
            'grupo',
            'precio_1',
            'precio 1',
            'precio1',
            'p1',
            'mayor_1',
            'mayor 1',
            'precio_mayor_1',
            'precio_2',
            'precio 2',
            'precio2',
            'p2',
            'mayor_2',
            'mayor 2',
            'precio_mayor_2',
            'precio_3',
            'precio 3',
            'precio3',
            'p3',
            'detal',
            'pvp',
            'pvp_detal',
            'precio_detal',
            'precio_3_detal',
            'brand_slug',
            'category_slugs',
            'tag_slugs',
            'unit_of_measure',
            'tracking_type',
            'base_price',
            'sale_currency',
            'min_stock',
            'max_stock',
            'reorder_quantity',
            'is_active',
            'stock_inicial',
            'almacen_codigo',
            'costo_unitario',
        ];
    }

    public function naturalKey(array $payload): string
    {
        return trim((string) ($payload['sku'] ?? ''));
    }

    protected function processRow(array $payload, int $rowNumber): ImportRowResult
    {
        $providedValues = $payload;
        $providedFields = array_fill_keys(array_keys($payload), true);
        $payload = $this->fillDefaults($payload);
        $sku = $payload['sku'];
        $name = $payload['name'];
        $barcode = $payload['barcode'];
        $description = $payload['description'];
        $brandSlug = $payload['brand_slug'];
        $categorySlugs = $payload['category_slugs'];
        $tagSlugs = $payload['tag_slugs'];
        $unitOfMeasure = $payload['unit_of_measure'];
        $trackingType = $payload['tracking_type'];
        $basePrice = $payload['base_price'];
        $p1 = $payload['precio_1'];
        $p2 = $payload['precio_2'];
        $p3 = $payload['precio_3'];
        $saleCurrency = $payload['sale_currency'];
        $minStock = $payload['min_stock'];
        $maxStock = $payload['max_stock'];
        $reorderQty = $payload['reorder_quantity'];
        $isActive = $payload['is_active'];
        $stockInicial = $payload['stock_inicial'];
        $almacenCodigo = $payload['almacen_codigo'];
        $costoUnitario = $payload['costo_unitario'];

        $errors = [];
        if (! $sku) {
            $errors['sku'] = 'sku es obligatorio';
        } elseif (mb_strlen($sku) > 255) {
            $errors['sku'] = 'sku excede 255 caracteres';
        }
        if (! $name) {
            $errors['name'] = 'name es obligatorio';
        }
        if (! $unitOfMeasure || mb_strlen($unitOfMeasure) > 20) {
            $errors['unit_of_measure'] = 'unit_of_measure no puede estar vacío ni superar 20 caracteres';
        }

        if (! in_array($trackingType, ['quantity', 'serialized'], true)) {
            $errors['tracking_type'] = 'tracking_type debe ser quantity o serialized';
        }
        if (! in_array($saleCurrency, ['USD', 'VES'], true)) {
            $errors['sale_currency'] = 'sale_currency debe ser USD o VES';
        }
        if ($basePrice < 0) {
            $errors['base_price'] = 'base_price no puede ser negativo';
        }
        if ($minStock !== null && $minStock < 0) {
            $errors['min_stock'] = 'min_stock no puede ser negativo';
        }
        if ($maxStock !== null && $minStock !== null && $maxStock < $minStock) {
            $errors['max_stock'] = 'max_stock debe ser >= min_stock';
        }
        if ($stockInicial !== null && $stockInicial < 0) {
            $errors['stock_inicial'] = 'stock_inicial no puede ser negativo';
        }
        if ($stockInicial !== null && $stockInicial > 0 && ! $almacenCodigo) {
            $errors['almacen_codigo'] = 'almacen_codigo requerido si defines stock_inicial';
        }
        if ($costoUnitario !== null && $costoUnitario < 0) {
            $errors['costo_unitario'] = 'costo_unitario no puede ser negativo';
        }
        if ($errors !== []) {
            return ImportRowResult::failed($errors, $sku);
        }

        $brandId = null;
        if ($brandSlug) {
            $brand = Brand::query()->where('slug', $this->normalizeSlug($brandSlug))->first();
            if (! $brand) {
                return ImportRowResult::failed(
                    ['brand_slug' => "Marca '{$brandSlug}' no existe. Importala primero."],
                    $sku,
                );
            }
            $brandId = $brand->id;
        }

        $categoryFieldProvided = false;
        $categoryRawValue = null;
        foreach (['category', 'categoria', 'category_slugs', 'category_slug', 'categoria_slug', 'categorias', 'rubro', 'departamento', 'grupo'] as $k) {
            if (isset($providedFields[$k])) {
                $categoryFieldProvided = true;
                if ($providedValues[$k] !== null && trim((string) $providedValues[$k]) !== '') {
                    $categoryRawValue = (string) $providedValues[$k];
                }
                break;
            }
        }

        $categoryIds = $categoryFieldProvided && $categoryRawValue !== null
            ? $this->resolveOrCreateCategories($categoryRawValue)
            : ($categoryFieldProvided ? [] : null);

        $tagIds = [];
        if ($tagSlugs) {
            $slugs = array_filter(array_map('trim', explode('|', $tagSlugs)));
            foreach ($slugs as $slug) {
                $normalizedSlug = $this->normalizeSlug($slug);
                $tag = Tag::query()->where('slug', $normalizedSlug)->first();
                if (! $tag) {
                    return ImportRowResult::failed(
                        ['tag_slugs' => "Tag '{$slug}' no existe. Importala primero."],
                        $sku,
                    );
                }
                $tagIds[] = $tag->id;
            }
        }

        $warehouseId = null;
        if ($almacenCodigo) {
            $warehouse = Warehouse::query()->where('code', $almacenCodigo)->first();
            if (! $warehouse) {
                return ImportRowResult::failed(
                    ['almacen_codigo' => "Almacen '{$almacenCodigo}' no existe."],
                    $sku,
                );
            }
            $warehouseId = $warehouse->id;
        }

        return DB::transaction(function () use (
            $sku, $name, $barcode, $description, $brandId, $categoryIds, $categoryFieldProvided, $tagIds,
            $unitOfMeasure, $trackingType, $basePrice, $saleCurrency, $p1, $p2, $p3,
            $minStock, $maxStock, $reorderQty, $isActive,
            $stockInicial, $warehouseId, $costoUnitario, $providedFields, $providedValues,
        ) {
            $existing = Product::query()->where('sku', $sku)->first();
            if ($existing) {
                return $this->updateExistingProduct(
                    $existing,
                    $sku,
                    array_filter([
                        'name' => $name,
                        'barcode' => $barcode,
                        'description' => $description,
                        'brand_id' => $brandId,
                        'unit_of_measure' => $unitOfMeasure,
                        'tracking_type' => $trackingType,
                        'base_price' => $basePrice,
                        'sale_currency' => $saleCurrency,
                        'min_stock' => $minStock,
                        'max_stock' => $maxStock,
                        'reorder_quantity' => $reorderQty,
                        'is_active' => $isActive,
                    ], function ($value, $field) use ($providedFields, $providedValues): bool {
                        $payloadField = $field === 'brand_id' ? 'brand_slug' : $field;

                        return isset($providedFields[$payloadField]) && $providedValues[$payloadField] !== null;
                    }, ARRAY_FILTER_USE_BOTH),
                    $categoryFieldProvided ? $categoryIds : null,
                    isset($providedFields['tag_slugs']) && $providedValues['tag_slugs'] !== null ? $tagIds : null,
                    $p1,
                    $p2,
                    $p3,
                    $saleCurrency,
                );
            }

            $product = Product::create([
                'sku' => $sku,
                'name' => $name,
                'barcode' => $barcode,
                'description' => $description,
                'brand_id' => $brandId,
                'unit_of_measure' => $unitOfMeasure,
                'track_stock' => true,
                'tracking_type' => $trackingType,
                'base_price' => $basePrice,
                'sale_currency' => $saleCurrency,
                'min_stock' => $minStock,
                'max_stock' => $maxStock,
                'reorder_quantity' => $reorderQty,
                'is_active' => $isActive,
                'is_catalog_master' => app(TenantManager::class)->current()?->isGroup() ?? false,
            ]);

            if (! empty($categoryIds)) {
                $product->categories()->syncWithPivotValues($categoryIds, ['tenant_id' => $product->tenant_id]);
            }
            if (! empty($tagIds)) {
                $product->tags()->syncWithPivotValues($tagIds, ['tenant_id' => $product->tenant_id]);
            }

            $this->syncProductPrices($product, $p1, $p2, $p3, $saleCurrency);

            app(SyncCatalogOutboxService::class)->productCreated($product->refresh()->load(['brand', 'categories', 'tags']));

            if ($stockInicial !== null && $stockInicial > 0 && $warehouseId !== null) {
                try {
                    $user = Auth::user() ?? User::query()->first();
                    if (! $user) {
                        return ImportRowResult::failed(
                            ['stock_inicial' => 'No se pudo registrar inventario: no hay usuario en sesion para firmar la entrada.'],
                            $sku,
                        );
                    }
                    app(ProductEntryService::class)->create($user, [
                        'reason' => 'Importacion inicial',
                        'notes' => "Generado automaticamente desde importacion para SKU {$sku}",
                        'items' => [[
                            'warehouse_id' => $warehouseId,
                            'product_id' => $product->id,
                            'quantity' => $stockInicial,
                            'unit_cost' => $costoUnitario,
                        ]],
                    ]);
                } catch (\Throwable $e) {
                    return ImportRowResult::failed(
                        ['stock_inicial' => 'Producto creado, pero no se pudo registrar inventario: '.$e->getMessage()],
                        $sku,
                    );
                }
            }

            // Replicar el producto a los spinoffs del grupo, igual que
            // lo hace ProductController::store. Tambien propaga los
            // catalogos referenciados (brand, categorias, tags,
            // warranty policy, exchange rate type) si todavia no
            // existen localmente en el spinoff. El import se ejecuta
            // desde el grupo (el tenant actual es el grupo), asi que
            // el servicio propaga el maestro a cada spinoff activo.
            try {
                if ($product->isCatalogMaster()) {
                    $this->propagation->propagateMaster($product);
                    $this->propagation->propagateReferencedCatalogForMaster($product);
                }
            } catch (\Throwable $e) {
                // Si la propagacion falla (por ejemplo, el grupo no
                // tiene spinoffs activos, o el sync catalog falla), NO
                // abortamos el import: el producto ya se creo en el
                // grupo. Logueamos y seguimos.
                if (function_exists('logger')) {
                    logger()->warning('Propagacion de producto importado fallo', [
                        'sku' => $sku,
                        'product_id' => $product->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return ImportRowResult::ok($product->id, $sku);
        });
    }

    /**
     * Actualiza un producto existente (re-subida del archivo). No toca el
     * stock: el inventario se maneja con movimientos, no con la importacion.
     * Devuelve `updated` si algo cambio, o `skipped` si quedo igual.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int>  $categoryIds
     * @param  array<int, int>  $tagIds
     */
    private function updateExistingProduct(
        Product $product,
        string $sku,
        array $attributes,
        ?array $categoryIds,
        ?array $tagIds,
        ?float $p1 = null,
        ?float $p2 = null,
        ?float $p3 = null,
        string $saleCurrency = 'USD',
    ): ImportRowResult {
        $dirty = [];
        foreach ($attributes as $field => $value) {
            $current = $product->getAttribute($field);
            $same = match ($field) {
                'is_active' => (bool) $current === (bool) $value,
                'base_price', 'min_stock', 'max_stock', 'reorder_quantity' => (float) ($current ?? 0) === (float) ($value ?? 0),
                default => (string) ($current ?? '') === (string) ($value ?? ''),
            };
            if (! $same) {
                $dirty[$field] = $value;
            }
        }

        $currentCategoryIds = $categoryIds === null ? [] : DB::table('product_category')
            ->where('product_id', $product->id)
            ->where('tenant_id', $product->tenant_id)
            ->pluck('category_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
        $desiredCategoryIds = $categoryIds === null ? [] : collect($categoryIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
        $categoriesChanged = $categoryIds !== null && $currentCategoryIds !== $desiredCategoryIds;

        $currentTagIds = $tagIds === null ? [] : DB::table('product_tag')
            ->where('product_id', $product->id)
            ->where('tenant_id', $product->tenant_id)
            ->pluck('tag_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
        $desiredTagIds = $tagIds === null ? [] : collect($tagIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
        $tagsChanged = $tagIds !== null && $currentTagIds !== $desiredTagIds;

        $pricesChanged = $this->syncProductPrices($product, $p1, $p2, $p3, $saleCurrency);

        if ($dirty === [] && ! $categoriesChanged && ! $tagsChanged && ! $pricesChanged) {
            return ImportRowResult::skipped("Producto {$sku} sin cambios", $sku);
        }

        if ($dirty !== []) {
            $product->update($dirty);
        }
        if ($categoriesChanged) {
            $product->categories()->syncWithPivotValues(
                $desiredCategoryIds,
                ['tenant_id' => $product->tenant_id],
            );
        }
        if ($tagsChanged) {
            $product->tags()->syncWithPivotValues(
                $desiredTagIds,
                ['tenant_id' => $product->tenant_id],
            );
        }

        $product->refresh()->load(['brand', 'categories', 'tags']);
        app(SyncCatalogOutboxService::class)->productUpdated($product);

        try {
            if ($product->isCatalogMaster()) {
                $this->propagation->syncMasterFieldsToCopies($product);
            }
        } catch (\Throwable $e) {
            logger()->warning('Propagacion de producto actualizado fallo', [
                'sku' => $sku,
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ImportRowResult::updated($product->id, $sku, "Producto {$sku} actualizado");
    }

    protected function parseBool(?string $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        $v = strtolower(trim($value));
        if (in_array($v, ['1', 'true', 't', 'si', 'yes', 'y', 'activo', 'active'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'f', 'no', 'n', 'inactivo', 'inactive'], true)) {
            return false;
        }

        return $default;
    }

    /**
     * Resuelve o crea las categorías a partir de nombres o slugs.
     * Si una categoría existe (por slug o por nombre), la asocia.
     * Si no existe, la crea automáticamente.
     *
     * @return array<int, int>
     */
    private function resolveOrCreateCategories(string $rawCategories): array
    {
        $categoryIds = [];
        $items = array_filter(array_map('trim', preg_split('/[|,]/', $rawCategories)));

        foreach ($items as $item) {
            if ($item === '') {
                continue;
            }

            $normalizedSlug = $this->normalizeSlug($item);

            $cat = null;
            if ($normalizedSlug !== null) {
                $cat = Category::query()->where('slug', $normalizedSlug)->first();
            }

            if (! $cat) {
                $cat = Category::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($item)])->first();
            }

            if ($cat) {
                $categoryIds[] = $cat->id;
                continue;
            }

            $slug = $normalizedSlug ?? Str::slug($item);
            if ($slug === '') {
                $slug = 'cat-'.Str::random(6);
            }

            $baseSlug = $slug;
            $counter = 1;
            while (Category::query()->where('slug', $slug)->exists()) {
                $slug = "{$baseSlug}-{$counter}";
                $counter++;
            }

            $category = Category::create([
                'name' => $item,
                'slug' => $slug,
                'description' => null,
                'sort_order' => 0,
                'is_active' => true,
            ]);

            app(SyncCatalogOutboxService::class)->categoryCreated($category->refresh());
            $categoryIds[] = $category->id;
        }

        return array_values(array_unique($categoryIds));
    }

    /**
     * @var array<int, array<string, PriceList>>
     */
    private array $priceListsCache = [];

    private function resolveTenantPriceLists(int $tenantId): array
    {
        if (isset($this->priceListsCache[$tenantId])) {
            return $this->priceListsCache[$tenantId];
        }

        $lists = PriceList::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->get();
        $map = [];

        // 1. Mapeo directo por código exacto (P1, P2, P3)
        foreach ($lists as $list) {
            $codeUpper = strtoupper(trim((string) $list->code));
            if (in_array($codeUpper, ['P1', 'P2', 'P3'], true)) {
                $map[$codeUpper] = $list;
            }
        }

        // 2. Mapeo por nombre si no se encontró por código
        foreach ($lists as $list) {
            $nameUpper = strtoupper(trim((string) $list->name));
            if (! isset($map['P1']) && (str_contains($nameUpper, 'PRECIO 1') || str_contains($nameUpper, 'MAYOR 1') || str_ends_with($nameUpper, ' 1'))) {
                $map['P1'] = $list;
            }
            if (! isset($map['P2']) && (str_contains($nameUpper, 'PRECIO 2') || str_contains($nameUpper, 'MAYOR 2') || str_ends_with($nameUpper, ' 2'))) {
                $map['P2'] = $list;
            }
            if (! isset($map['P3']) && (str_contains($nameUpper, 'PRECIO 3') || str_contains($nameUpper, 'DETAL') || str_ends_with($nameUpper, ' 3') || $list->is_default)) {
                $map['P3'] = $list;
            }
        }

        // 3. Si no existen, crearlas para que los precios siempre tengan donde guardarse
        $defaultDefs = [
            'P1' => ['name' => 'PRECIO 1', 'is_default' => false, 'sort_order' => 1],
            'P2' => ['name' => 'PRECIO 2', 'is_default' => false, 'sort_order' => 2],
            'P3' => ['name' => 'PRECIO 3', 'is_default' => true, 'sort_order' => 3],
        ];

        foreach ($defaultDefs as $key => $def) {
            if (! isset($map[$key])) {
                $created = PriceList::create([
                    'tenant_id' => $tenantId,
                    'code' => $key,
                    'name' => $def['name'],
                    'is_default' => $def['is_default'],
                    'is_active' => true,
                    'sort_order' => $def['sort_order'],
                ]);
                app(SyncCatalogOutboxService::class)->priceListCreated($created);
                $map[$key] = $created;
            }
        }

        return $this->priceListsCache[$tenantId] = $map;
    }

    private function extractPriceValue(array $payload, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && trim((string) $payload[$key]) !== '') {
                return $this->normalizeDecimal($payload[$key]);
            }
        }

        return null;
    }

    private function syncProductPrices(
        Product $product,
        ?float $p1,
        ?float $p2,
        ?float $p3,
        string $saleCurrency,
    ): bool {
        if ($p1 === null && $p2 === null && $p3 === null) {
            return false;
        }

        $priceMap = [
            'P1' => $p1,
            'P2' => $p2,
            'P3' => $p3,
        ];

        $lists = $this->resolveTenantPriceLists($product->tenant_id);
        $changed = false;

        foreach ($priceMap as $key => $priceValue) {
            if ($priceValue === null) {
                continue;
            }

            $priceList = $lists[$key] ?? null;
            if (! $priceList) {
                continue;
            }

            $existing = ProductPrice::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $product->tenant_id)
                ->where('product_id', $product->id)
                ->where('price_list_id', $priceList->id)
                ->first();

            if ($existing) {
                if ((float) $existing->price !== (float) $priceValue || $existing->currency !== $saleCurrency) {
                    $existing->update([
                        'price' => $priceValue,
                        'currency' => $saleCurrency,
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
                    app(SyncCatalogOutboxService::class)->productPriceUpdated($existing->fresh());
                    $changed = true;
                }
            } else {
                $newPrice = ProductPrice::create([
                    'tenant_id' => $product->tenant_id,
                    'product_id' => $product->id,
                    'price_list_id' => $priceList->id,
                    'price' => $priceValue,
                    'currency' => $saleCurrency,
                    'is_active' => true,
                ]);
                app(SyncCatalogOutboxService::class)->productPriceCreated($newPrice);
                $changed = true;
            }
        }

        return $changed;
    }

    private function fillDefaults(array $payload): array
    {
        $p1 = $this->extractPriceValue($payload, ['precio_1', 'precio1', 'p1', 'precio_mayor_1', 'mayor_1', 'precio 1', 'mayor 1']);
        $p2 = $this->extractPriceValue($payload, ['precio_2', 'precio2', 'p2', 'precio_mayor_2', 'mayor_2', 'precio 2', 'mayor 2']);
        $p3 = $this->extractPriceValue($payload, ['precio_3', 'precio3', 'p3', 'precio_detal', 'pvp', 'detal', 'pvp_detal', 'precio 3', 'precio 3 detal']);

        $basePrice = $this->normalizeDecimal($payload['base_price'] ?? null);
        if ($basePrice === null) {
            $basePrice = $p1 ?? $p3 ?? $p2 ?? 0.0;
        }

        return [
            'sku' => $payload['sku'] ?? null,
            'name' => $payload['name'] ?? null,
            'barcode' => $payload['barcode'] ?? null,
            'description' => $payload['description'] ?? null,
            'category' => $payload['category'] ?? $payload['categoria'] ?? null,
            'brand_slug' => $payload['brand_slug'] ?? null,
            'category_slugs' => $payload['category_slugs'] ?? $payload['category'] ?? $payload['categoria'] ?? null,
            'tag_slugs' => $payload['tag_slugs'] ?? null,
            'unit_of_measure' => strtolower($payload['unit_of_measure'] ?? 'unit'),
            'tracking_type' => strtolower($payload['tracking_type'] ?? 'quantity'),
            'base_price' => $basePrice,
            'precio_1' => $p1,
            'precio_2' => $p2,
            'precio_3' => $p3,
            'sale_currency' => strtoupper($payload['sale_currency'] ?? 'USD'),
            'min_stock' => $this->normalizeDecimal($payload['min_stock'] ?? null),
            'max_stock' => $this->normalizeDecimal($payload['max_stock'] ?? null),
            'reorder_quantity' => $this->normalizeDecimal($payload['reorder_quantity'] ?? null),
            'is_active' => $this->parseBool($payload['is_active'] ?? null, true),
            'stock_inicial' => $this->normalizeDecimal($payload['stock_inicial'] ?? null),
            'almacen_codigo' => $payload['almacen_codigo'] ?? null,
            'costo_unitario' => $this->normalizeDecimal($payload['costo_unitario'] ?? null),
        ];
    }
}
