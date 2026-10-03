<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
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

    public function naturalKey(array $payload): string
    {
        return strtoupper(trim((string) ($payload['code'] ?? '')));
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
            $outbox = app(SyncCatalogOutboxService::class);

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

            $prices = null;
            if ($pricesRaw) {
                $prices = json_decode($pricesRaw, true);
                if (! is_array($prices)) {
                    return ImportRowResult::failed(
                        ['prices' => 'prices debe ser JSON valido con formato [{"sku":"...","price":0.0,"currency":"USD"}]'],
                        $code,
                    );
                }
            }

            $existing = PriceList::query()->where('code', $code)->first();
            if ($existing) {
                return $this->updateExistingList($existing, $code, [
                    'name' => $name,
                    'description' => $description,
                    'is_default' => $isDefault,
                    'is_active' => $isActive,
                    'sort_order' => $sortOrder,
                ], $pmIds, $prices, $outbox);
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

            if (! empty($pmIds)) {
                $list->paymentMethods()->syncWithPivotValues($pmIds, ['tenant_id' => $list->tenant_id]);
            }

            $outbox->priceListCreated($list->refresh()->load('paymentMethods'));

            if ($prices !== null) {
                [, $error] = $this->applyPrices($list, $prices, $outbox);
                if ($error !== null) {
                    return $error;
                }
            }

            return ImportRowResult::ok($list->id, $code);
        });
    }

    /**
     * Actualiza una lista existente (re-subida). Actualiza metadata, metodos
     * de pago y precios. Devuelve `updated` si algo cambio, `skipped` si no.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int>  $pmIds
     * @param  array<int, array<string, mixed>>|null  $prices
     */
    private function updateExistingList(
        PriceList $list,
        string $code,
        array $attributes,
        array $pmIds,
        ?array $prices,
        SyncCatalogOutboxService $outbox,
    ): ImportRowResult {
        $dirty = [];
        foreach ($attributes as $field => $value) {
            $current = $list->getAttribute($field);
            $same = match ($field) {
                'is_default', 'is_active' => (bool) $current === (bool) $value,
                'sort_order' => (int) $current === (int) $value,
                default => (string) ($current ?? '') === (string) ($value ?? ''),
            };
            if (! $same) {
                $dirty[$field] = $value;
            }
        }

        $currentPmIds = DB::table('price_list_payment_method')
            ->where('price_list_id', $list->id)
            ->where('tenant_id', $list->tenant_id)
            ->pluck('payment_method_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
        $desiredPmIds = collect($pmIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
        $pmChanged = $currentPmIds !== $desiredPmIds;

        $pricesChanged = false;
        if ($prices !== null) {
            [$pricesChanged, $error] = $this->applyPrices($list, $prices, $outbox);
            if ($error !== null) {
                return $error;
            }
        }

        if ($dirty === [] && ! $pmChanged && ! $pricesChanged) {
            return ImportRowResult::skipped("Lista de precios {$code} sin cambios", $code);
        }

        if ($dirty !== []) {
            $list->update($dirty);
        }
        if ($attributes['is_default'] ?? false) {
            PriceList::query()
                ->where('id', '!=', $list->id)
                ->update(['is_default' => false]);
        }
        if ($pmChanged) {
            $list->paymentMethods()->syncWithPivotValues($desiredPmIds, ['tenant_id' => $list->tenant_id]);
        }

        $outbox->priceListUpdated($list->refresh()->load('paymentMethods'));

        return ImportRowResult::updated($list->id, $code, "Lista de precios {$code} actualizada");
    }

    /**
     * Inserta o actualiza los precios de una lista. Devuelve [cambio?, error?].
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: bool, 1: ImportRowResult|null}
     */
    private function applyPrices(PriceList $list, array $items, SyncCatalogOutboxService $outbox): array
    {
        $changed = false;

        foreach ($items as $priceItem) {
            if (! isset($priceItem['sku'], $priceItem['price'])) {
                return [false, ImportRowResult::failed(
                    ['prices' => 'Cada item de prices requiere sku y price'],
                    $list->code,
                )];
            }
            $product = Product::query()->where('sku', $priceItem['sku'])->first();
            if (! $product) {
                return [false, ImportRowResult::failed(
                    ['prices' => "Producto SKU '{$priceItem['sku']}' no existe."],
                    $list->code,
                )];
            }

            $price = $this->normalizeDecimal($priceItem['price']) ?? 0.0;
            $currency = strtoupper((string) ($priceItem['currency'] ?? 'USD'));

            $existingPrice = ProductPrice::query()
                ->where('product_id', $product->id)
                ->where('price_list_id', $list->id)
                ->first();

            if ($existingPrice) {
                if ((float) $existingPrice->price !== $price
                    || strtoupper((string) $existingPrice->currency) !== $currency
                    || ! $existingPrice->is_active) {
                    $existingPrice->update(['price' => $price, 'currency' => $currency, 'is_active' => true]);
                    $outbox->productPriceUpdated($existingPrice->refresh());
                    $changed = true;
                }
            } else {
                $created = ProductPrice::create([
                    'product_id' => $product->id,
                    'price_list_id' => $list->id,
                    'price' => $price,
                    'currency' => $currency,
                    'is_active' => true,
                ]);
                $outbox->productPriceCreated($created->refresh());
                $changed = true;
            }
        }

        return [$changed, null];
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
