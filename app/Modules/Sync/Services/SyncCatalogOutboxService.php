<?php

namespace App\Modules\Sync\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\ProductEntries\Models\ProductEntry;
use App\Modules\ProductExits\Models\ProductExit;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPrice;
use Illuminate\Support\Str;

class SyncCatalogOutboxService
{
    public function __construct(private readonly SyncOutboxService $outbox)
    {
    }

    public function productCreated(Product $product): void
    {
        $this->recordProduct('product.created', $product);
    }

    public function productUpdated(Product $product): void
    {
        $this->recordProduct('product.updated', $product);
    }

    public function productDeactivated(Product $product): void
    {
        $this->recordProduct('product.updated', $product);
    }

    public function priceListCreated(PriceList $priceList): void
    {
        $this->recordPriceList('price_list.created', $priceList);
    }

    public function priceListUpdated(PriceList $priceList): void
    {
        $this->recordPriceList('price_list.updated', $priceList);
    }

    public function priceListDeactivated(PriceList $priceList): void
    {
        $this->recordPriceList('price_list.updated', $priceList);
    }

    public function productPriceCreated(ProductPrice $productPrice): void
    {
        $this->recordProductPrice('product_price.created', $productPrice);
    }

    public function productPriceUpdated(ProductPrice $productPrice): void
    {
        $this->recordProductPrice('product_price.updated', $productPrice);
    }

    public function customerCreated(Customer $customer): void
    {
        $this->recordCustomer('customer.created', $customer);
    }

    public function customerUpdated(Customer $customer): void
    {
        $this->recordCustomer('customer.updated', $customer);
    }

    public function customerDeactivated(Customer $customer): void
    {
        $this->recordCustomer('customer.updated', $customer);
    }

    public function productEntryCreated(ProductEntry $entry): void
    {
        $entry->loadMissing(['items.product', 'items.warehouse']);

        $this->outbox->record(
            eventType: 'product_entry.created',
            aggregateType: 'product_entry',
            aggregateId: $entry->id,
            payload: [
                'document_number' => $entry->document_number,
                'reason' => $entry->reason,
                'reference' => $entry->reference,
                'notes' => $entry->notes,
                'status' => $entry->status,
                'processed_at' => $entry->processed_at?->toISOString(),
                'items' => $entry->items->map(fn ($item): array => [
                    'sku' => $item->product?->sku,
                    'warehouse_code' => $item->warehouse?->code,
                    'quantity' => (string) $item->quantity,
                    'unit_cost' => $item->unit_cost === null ? null : (string) $item->unit_cost,
                    'serial_units' => $item->serial_units ?? [],
                ])->values()->all(),
            ],
            idempotencyKey: $this->eventKey('product_entry.created', 'product_entry', $entry->id),
        );
    }

    public function productExitCreated(ProductExit $exit): void
    {
        $exit->loadMissing(['items.product', 'items.warehouse']);

        $this->outbox->record(
            eventType: 'product_exit.created',
            aggregateType: 'product_exit',
            aggregateId: $exit->id,
            payload: [
                'document_number' => $exit->document_number,
                'reason' => $exit->reason,
                'reference' => $exit->reference,
                'notes' => $exit->notes,
                'status' => $exit->status,
                'processed_at' => $exit->processed_at?->toISOString(),
                'items' => $exit->items->map(fn ($item): array => [
                    'sku' => $item->product?->sku,
                    'warehouse_code' => $item->warehouse?->code,
                    'quantity' => (string) $item->quantity,
                    'product_unit_ids' => $item->product_unit_ids ?? [],
                ])->values()->all(),
            ],
            idempotencyKey: $this->eventKey('product_exit.created', 'product_exit', $exit->id),
        );
    }

    private function recordProduct(string $eventType, Product $product): void
    {
        $product->loadMissing(['saleExchangeRateType', 'warrantyPolicy']);

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'product',
            aggregateId: $product->id,
            payload: [
                'sku' => $product->sku,
                'name' => $product->name,
                'tracking_type' => $product->tracking_type,
                'base_price' => $product->base_price === null ? null : (string) $product->base_price,
                'sale_currency' => $product->sale_currency,
                'sale_exchange_rate_type_id' => $product->sale_exchange_rate_type_id,
                'sale_exchange_rate_type_code' => $product->saleExchangeRateType?->code,
                'warranty_policy_id' => $product->warranty_policy_id,
                'warranty_policy_name' => $product->warrantyPolicy?->name,
                'warranty_policy_duration_days' => $product->warrantyPolicy?->duration_days,
                'warranty_policy_coverage_type' => $product->warrantyPolicy?->coverage_type,
                'warranty_policy_conditions' => $product->warrantyPolicy?->conditions,
                'warranty_policy_is_active' => $product->warrantyPolicy ? (bool) $product->warrantyPolicy->is_active : null,
                'is_active' => (bool) $product->is_active,
            ],
            idempotencyKey: $this->eventKey($eventType, 'product', $product->id),
        );
    }

    private function recordPriceList(string $eventType, PriceList $priceList): void
    {
        $priceList->loadMissing('paymentMethods');

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'price_list',
            aggregateId: $priceList->id,
            payload: [
                'code' => $priceList->code,
                'name' => $priceList->name,
                'description' => $priceList->description,
                'is_default' => (bool) $priceList->is_default,
                'is_active' => (bool) $priceList->is_active,
                'sort_order' => (int) $priceList->sort_order,
                'payment_method_codes' => $priceList->paymentMethods
                    ->pluck('code')
                    ->filter()
                    ->values()
                    ->all(),
            ],
            idempotencyKey: $this->eventKey($eventType, 'price_list', $priceList->id),
        );
    }

    private function recordProductPrice(string $eventType, ProductPrice $productPrice): void
    {
        $productPrice->loadMissing(['product', 'priceList', 'exchangeRateType']);

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'product_price',
            aggregateId: $productPrice->id,
            payload: [
                'sku' => $productPrice->product?->sku,
                'price_list_code' => $productPrice->priceList?->code,
                'price' => (string) $productPrice->price,
                'currency' => $productPrice->currency,
                'exchange_rate_type_id' => $productPrice->exchange_rate_type_id,
                'exchange_rate_type_code' => $productPrice->exchangeRateType?->code,
                'is_active' => (bool) $productPrice->is_active,
            ],
            idempotencyKey: $this->eventKey($eventType, 'product_price', $productPrice->id),
        );
    }

    private function recordCustomer(string $eventType, Customer $customer): void
    {
        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'customer',
            aggregateId: $customer->id,
            payload: [
                'name' => $customer->name,
                'document_type' => $customer->document_type,
                'document_number' => $customer->document_number,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'fiscal_address' => $customer->fiscal_address,
                'is_generic' => (bool) $customer->is_generic,
                'is_active' => (bool) $customer->is_active,
            ],
            idempotencyKey: $this->eventKey($eventType, 'customer', $customer->id),
        );
    }

    private function eventKey(string $eventType, string $aggregateType, ?int $aggregateId): string
    {
        return implode(':', [
            $eventType,
            $aggregateType,
            $aggregateId ?? 'none',
            (string) Str::uuid(),
        ]);
    }
}
