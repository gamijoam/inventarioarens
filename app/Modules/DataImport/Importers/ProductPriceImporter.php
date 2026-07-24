<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPrice;
use Illuminate\Support\Facades\DB;

class ProductPriceImporter extends BaseImporter
{
    public function entity(): string
    {
        return 'product_prices';
    }

    public function headers(): array
    {
        return ['sku', 'list_code', 'price', 'currency', 'is_active', 'exchange_rate_type_code'];
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

        $product = Product::query()->where('sku', $sku)->first();
        if (! $product) {
            return ImportRowResult::failed(
                ['sku' => "Producto SKU '{$sku}' no existe. Importalo primero."],
                $sku.':'.$listCode,
            );
        }

        $list = PriceList::query()->where('code', $listCode)->first();
        if (! $list) {
            return ImportRowResult::failed(
                ['list_code' => "Lista de precios '{$listCode}' no existe. Crea la lista primero."],
                $sku.':'.$listCode,
            );
        }

        $rateTypeId = null;
        if ($rateTypeCode !== null) {
            $rateType = ExchangeRateType::query()->where('code', $rateTypeCode)->first();
            if (! $rateType) {
                return ImportRowResult::failed(
                    ['exchange_rate_type_code' => "Tipo de tasa '{$rateTypeCode}' no existe."],
                    $sku.':'.$listCode,
                );
            }
            $rateTypeId = $rateType->id;
        }

        return DB::transaction(function () use ($product, $list, $price, $currency, $isActive, $rateTypeId, $sku, $listCode) {
            $existing = ProductPrice::query()
                ->where('product_id', $product->id)
                ->where('price_list_id', $list->id)
                ->first();

            $attributes = [
                'price' => $price,
                'currency' => $currency,
                'exchange_rate_type_id' => $rateTypeId,
                'is_active' => $isActive,
            ];

            if ($existing) {
                $existing->update($attributes);
                $resultingId = $existing->id;
            } else {
                $created = ProductPrice::create([
                    'product_id' => $product->id,
                    'price_list_id' => $list->id,
                    ...$attributes,
                ]);
                $resultingId = $created->id;
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
}
