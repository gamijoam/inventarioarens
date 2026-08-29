<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use Illuminate\Support\Facades\DB;

class ProductPriceImporter extends BaseImporter
{
    /**
     * Mapas de referencias cargados una sola vez por ejecucion para no
     * repetir consultas por fila (productos, listas, tipos de tasa y
     * precios existentes). Reconstruido por importer instanciado.
     *
     * @var array{
     *   products: array<string, int>,
     *   lists: array<string, int>,
     *   rate_types: array<string, int>,
     *   existing_prices: array<string, ProductPrice>
     * }|null
     */
    private ?array $references = null;

    public function entity(): string
    {
        return 'product_prices';
    }

    public function headers(): array
    {
        return ['sku', 'list_code', 'price', 'currency', 'is_active', 'exchange_rate_type_code'];
    }

    public function naturalKey(array $payload): string
    {
        $sku = trim((string) ($payload['sku'] ?? ''));
        $listCode = strtoupper(trim((string) ($payload['list_code'] ?? '')));

        return $sku.':'.$listCode;
    }

    protected function processRow(array $payload, int $rowNumber): ImportRowResult
    {
        $sku = trim((string) ($payload['sku'] ?? ''));
        $listCode = strtoupper(trim((string) ($payload['list_code'] ?? '')));
        $priceRaw = $payload['price'] ?? null;
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'USD')));
        $isActive = $this->parseBool($payload['is_active'] ?? null, true);
        $rawRateType = $payload['exchange_rate_type_code'] ?? null;
        $rateTypeCode = $rawRateType !== null
            ? strtoupper(trim((string) $rawRateType))
            : null;
        if ($rateTypeCode === '') {
            $rateTypeCode = null;
        }

        $errors = [];
        if ($sku === '') {
            $errors['sku'] = 'sku es obligatorio';
        }
        if ($listCode === '') {
            $errors['list_code'] = 'list_code es obligatorio (codigo de la lista existente)';
        }
        $price = $this->normalizeDecimal($priceRaw);
        if ($price === null || $price < 0) {
            $errors['price'] = 'price es obligatorio y debe ser >= 0';
        }
        if (! in_array($currency, ['USD', 'VES'], true)) {
            $errors['currency'] = 'currency debe ser USD o VES';
        }
        if ($errors !== []) {
            return ImportRowResult::failed($errors, $sku.':'.$listCode);
        }

        $refs = $this->references();

        $productId = $refs['products'][$sku] ?? null;
        if ($productId === null) {
            return ImportRowResult::failed(
                ['sku' => "Producto SKU '{$sku}' no existe. Importalo primero."],
                $sku.':'.$listCode,
            );
        }

        $listId = $refs['lists'][$listCode] ?? null;
        if ($listId === null) {
            return ImportRowResult::failed(
                ['list_code' => "Lista de precios '{$listCode}' no existe. Crea la lista primero."],
                $sku.':'.$listCode,
            );
        }

        $rateTypeId = null;
        if ($rateTypeCode !== null) {
            $rateTypeId = $refs['rate_types'][$rateTypeCode] ?? null;
            if ($rateTypeId === null) {
                return ImportRowResult::failed(
                    ['exchange_rate_type_code' => "Tipo de tasa '{$rateTypeCode}' no existe."],
                    $sku.':'.$listCode,
                );
            }
        }

        $priceKey = $productId.':'.$listId;
        $existing = $refs['existing_prices'][$priceKey] ?? null;

        $attributes = [
            'price' => $price,
            'currency' => $currency,
            'exchange_rate_type_id' => $rateTypeId,
            'is_active' => $isActive,
        ];

        return DB::transaction(function () use ($existing, $attributes, $productId, $listId, $sku, $listCode) {
            $outbox = app(SyncCatalogOutboxService::class);

            if ($existing) {
                DB::table('product_prices')->where('id', $existing->id)->update($attributes);
                $existing->forceFill($attributes);
                $outbox->productPriceUpdated($existing);
                $resultingId = $existing->id;
            } else {
                $created = ProductPrice::create([
                    'product_id' => $productId,
                    'price_list_id' => $listId,
                    ...$attributes,
                ]);
                $resultingId = $created->id;
                $outbox->productPriceCreated($created->refresh());
            }

            return ImportRowResult::ok($resultingId, $sku.':'.$listCode);
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

    /**
     * @return array{
     *   products: array<string, int>,
     *   lists: array<string, int>,
     *   rate_types: array<string, int>,
     *   existing_prices: array<string, ProductPrice>
     * }
     */
    private function references(): array
    {
        if ($this->references !== null) {
            return $this->references;
        }

        $existingPrices = ProductPrice::query()
            ->get(['id', 'product_id', 'price_list_id'])
            ->keyBy(fn (ProductPrice $price): string => (int) $price->product_id.':'.(int) $price->price_list_id)
            ->all();

        return $this->references = [
            'products' => Product::query()->pluck('id', 'sku')->all(),
            'lists' => PriceList::query()->pluck('id', 'code')->all(),
            'rate_types' => ExchangeRateType::query()->pluck('id', 'code')->all(),
            'existing_prices' => $existingPrices,
        ];
    }
}
