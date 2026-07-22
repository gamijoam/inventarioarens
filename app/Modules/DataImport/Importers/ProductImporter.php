<?php

namespace App\Modules\DataImport\Importers;

use App\Models\User;
use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\ProductEntries\Services\ProductEntryService;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\Tag;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductImporter extends BaseImporter
{
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

    protected function processRow(array $payload, int $rowNumber): ImportRowResult
    {
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
        if (! in_array($unitOfMeasure, ['unit', 'kg', 'lt', 'm'], true)) {
            $errors['unit_of_measure'] = 'unit_of_measure debe ser unit, kg, lt o m';
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
            $brand = Brand::query()->where('slug', $brandSlug)->first();
            if (! $brand) {
                return ImportRowResult::failed(
                    ['brand_slug' => "Marca '{$brandSlug}' no existe. Importala primero."],
                    $sku,
                );
            }
            $brandId = $brand->id;
        }

        $categoryIds = [];
        if ($categorySlugs) {
            $slugs = array_filter(array_map('trim', explode('|', $categorySlugs)));
            foreach ($slugs as $slug) {
                $cat = Category::query()->where('slug', $slug)->first();
                if (! $cat) {
                    return ImportRowResult::failed(
                        ['category_slugs' => "Categoria '{$slug}' no existe. Importala primero."],
                        $sku,
                    );
                }
                $categoryIds[] = $cat->id;
            }
        }

        $tagIds = [];
        if ($tagSlugs) {
            $slugs = array_filter(array_map('trim', explode('|', $tagSlugs)));
            foreach ($slugs as $slug) {
                $tag = Tag::query()->where('slug', $slug)->first();
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
            $sku, $name, $barcode, $description, $brandId, $categoryIds, $tagIds,
            $unitOfMeasure, $trackingType, $basePrice, $saleCurrency,
            $minStock, $maxStock, $reorderQty, $isActive,
            $stockInicial, $warehouseId, $costoUnitario,
        ) {
            $existing = Product::query()->where('sku', $sku)->first();
            if ($existing) {
                return ImportRowResult::skipped("Producto {$sku} ya existe", $sku);
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
            ]);

            if (! empty($categoryIds)) {
                $product->categories()->syncWithPivotValues($categoryIds, ['tenant_id' => $product->tenant_id]);
            }
            if (! empty($tagIds)) {
                $product->tags()->syncWithPivotValues($tagIds, ['tenant_id' => $product->tenant_id]);
            }

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

            return ImportRowResult::ok($product->id, $sku);
        });
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

    private function fillDefaults(array $payload): array
    {
        return [
            'sku' => $payload['sku'] ?? null,
            'name' => $payload['name'] ?? null,
            'barcode' => $payload['barcode'] ?? null,
            'description' => $payload['description'] ?? null,
            'brand_slug' => $payload['brand_slug'] ?? null,
            'category_slugs' => $payload['category_slugs'] ?? null,
            'tag_slugs' => $payload['tag_slugs'] ?? null,
            'unit_of_measure' => strtolower($payload['unit_of_measure'] ?? 'unit'),
            'tracking_type' => strtolower($payload['tracking_type'] ?? 'quantity'),
            'base_price' => ($payload['base_price'] ?? '') !== '' ? (float) $payload['base_price'] : 0.0,
            'sale_currency' => strtoupper($payload['sale_currency'] ?? 'USD'),
            'min_stock' => ($payload['min_stock'] ?? '') !== '' ? (float) $payload['min_stock'] : null,
            'max_stock' => ($payload['max_stock'] ?? '') !== '' ? (float) $payload['max_stock'] : null,
            'reorder_quantity' => ($payload['reorder_quantity'] ?? '') !== '' ? (float) $payload['reorder_quantity'] : null,
            'is_active' => $this->parseBool($payload['is_active'] ?? null, true),
            'stock_inicial' => ($payload['stock_inicial'] ?? '') !== '' ? (float) $payload['stock_inicial'] : null,
            'almacen_codigo' => $payload['almacen_codigo'] ?? null,
            'costo_unitario' => ($payload['costo_unitario'] ?? '') !== '' ? (float) $payload['costo_unitario'] : null,
        ];
    }
}
