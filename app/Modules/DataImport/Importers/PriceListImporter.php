<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPrice;
use Illuminate\Support\Facades\DB;

class PriceListImporter extends BaseImporter
{
    public function entity(): string
    {
        return 'price_lists';
    }

    public function headers(): array
    {
        return ['code', 'name', 'description', 'is_default', 'is_active', 'sort_order', 'payment_method_codes', 'prices'];
    }

    protected function processRow(array $payload, int $rowNumber): ImportRowResult
    {
        $code = strtoupper($payload['code'] ?? '');
        $name = $payload['name'] ?? null;
        $description = $payload['description'] ?? null;
        $isDefault = $this->parseBool($payload['is_default'] ?? null, false);
        $isActive = $this->parseBool($payload['is_active'] ?? null, true);
        $sortOrder = (int) ($payload['sort_order'] ?? 0);
        $pmCodesRaw = $payload['payment_method_codes'] ?? null;
        $pricesRaw = $payload['prices'] ?? null;

        $errors = [];
        if (! $code || ! preg_match('/^[A-Z0-9_-]{1,30}$/', $code)) {
            $errors['code'] = 'code es obligatorio (mayusculas, guion, guion bajo)';
        }
        if (! $name) {
            $errors['name'] = 'name es obligatorio';
        }
        if ($errors !== []) {
            return ImportRowResult::failed($errors, $code);
        }

        return DB::transaction(function () use ($code, $name, $description, $isDefault, $isActive, $sortOrder, $pmCodesRaw, $pricesRaw) {
            $existing = PriceList::query()->where('code', $code)->first();
            if ($existing) {
                return ImportRowResult::skipped("Lista de precios {$code} ya existe", $code);
            }

            $list = PriceList::create([
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'is_default' => $isDefault,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ]);

            if ($isDefault) {
                PriceList::query()
                    ->where('id', '!=', $list->id)
                    ->update(['is_default' => false]);
            }

            $pmIds = [];
            if ($pmCodesRaw) {
                $codes = array_filter(array_map('trim', explode('|', strtoupper($pmCodesRaw))));
                foreach ($codes as $pmCode) {
                    $pm = PaymentMethod::query()->where('code', $pmCode)->first();
                    if (! $pm) {
                        return ImportRowResult::failed(
                            ['payment_method_codes' => "Metodo de pago '{$pmCode}' no existe."],
                            $code,
                        );
                    }
                    $pmIds[] = $pm->id;
                }
            }
            if (! empty($pmIds)) {
                $list->paymentMethods()->syncWithPivotValues($pmIds, ['tenant_id' => $list->tenant_id]);
            }

            if ($pricesRaw) {
                $items = json_decode($pricesRaw, true);
                if (! is_array($items)) {
                    return ImportRowResult::failed(
                        ['prices' => 'prices debe ser JSON valido con formato [{"sku":"...","price":0.0,"currency":"USD"}]'],
                        $code,
                    );
                }
                foreach ($items as $priceItem) {
                    if (! isset($priceItem['sku'], $priceItem['price'])) {
                        return ImportRowResult::failed(
                            ['prices' => 'Cada item de prices requiere sku y price'],
                            $code,
                        );
                    }
                    $product = Product::query()->where('sku', $priceItem['sku'])->first();
                    if (! $product) {
                        return ImportRowResult::failed(
                            ['prices' => "Producto SKU '{$priceItem['sku']}' no existe."],
                            $code,
                        );
                    }
                    ProductPrice::create([
                        'product_id' => $product->id,
                        'price_list_id' => $list->id,
                        'price' => (float) $priceItem['price'],
                        'currency' => strtoupper($priceItem['currency'] ?? 'USD'),
                        'is_active' => true,
                    ]);
                }
            }

            return ImportRowResult::ok($list->id, $code);
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
