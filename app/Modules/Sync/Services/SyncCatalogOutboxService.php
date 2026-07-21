<?php

namespace App\Modules\Sync\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequestItem;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\ProductEntries\Models\ProductEntry;
use App\Modules\ProductExits\Models\ProductExit;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Purchases\Models\PurchaseOrder;
use Carbon\CarbonInterface;
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

    public function imageUploaded(ProductImage $image): void
    {
        $this->recordProductImage('product.image.uploaded', $image, includeDeleted: false);
    }

    public function imageUpdated(ProductImage $image): void
    {
        $this->recordProductImage('product.image.updated', $image, includeDeleted: false);
    }

    public function imageDeleted(ProductImage $image): void
    {
        $this->recordProductImage('product.image.deleted', $image, includeDeleted: true);
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
            idempotencyKey: $this->eventKey('product_entry.created', 'product_entry', $entry->id, $entry->updated_at),
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
            idempotencyKey: $this->eventKey('product_exit.created', 'product_exit', $exit->id, $exit->updated_at),
        );
    }

    /**
     * Emite el evento de Orden de Compra en estado `draft`.
     * La nube usa esto solo para visibilidad/trazabilidad minima (el PO es
     * local-operational segun docs/SYNC_OPERATIONS.md §5). La recepcion
     * de mercancia es la que efectivamente crea stock en la nube
     * (ver purchaseOrderReceived).
     */
    public function purchaseOrderCreated(PurchaseOrder $order): void
    {
        $order->loadMissing(['supplier', 'items.product', 'items.warehouse']);

        $this->outbox->record(
            eventType: 'purchase_order.created',
            aggregateType: 'purchase_order',
            aggregateId: $order->id,
            payload: [
                'document_number' => $order->document_number,
                'status' => $order->status,
                'supplier_name' => $order->supplier?->name,
                'issued_at' => $order->issued_at?->toDateString(),
                'due_date' => $order->due_date?->toDateString(),
                'purchase_currency' => $order->purchase_currency,
                'exchange_rate_type_id' => $order->exchange_rate_type_id,
                'exchange_rate' => $order->exchange_rate === null ? null : (string) $order->exchange_rate,
                'total_base_amount' => (string) $order->total_base_amount,
                'total_local_amount' => (string) $order->total_local_amount,
                'items' => $order->items->map(fn ($item): array => [
                    'sku' => $item->product?->sku,
                    'warehouse_code' => $item->warehouse?->code,
                    'quantity' => (string) $item->quantity,
                    'unit_cost' => (string) $item->unit_cost,
                    'base_unit_cost' => (string) $item->base_unit_cost,
                ])->values()->all(),
            ],
            idempotencyKey: $this->eventKey('purchase_order.created', 'purchase_order', $order->id, $order->updated_at),
        );
    }

    /**
     * Emite el evento de recepcion de Orden de Compra. Este es el evento
     * que la nube usa para crear un `product_entries` con los items recibidos
     * (mantiene su stock en sync). El supplier no se replica; la nube registra
     * el nombre en `product_entries.notes` para referencia.
     */
    public function purchaseOrderReceived(PurchaseOrder $order): void
    {
        $order->loadMissing(['supplier', 'items.product', 'items.warehouse', 'items.stockMovement']);

        // Solo emitimos los items que efectivamente se recibieron en esta
        // operacion (los que tienen `received_quantity > 0` y un stock_movement
        // asociado). Esto evita emitir lineas pendientes en recepciones
        // parciales y permite idempotencia: re-procesar el mismo evento NO
        // duplica stock en la nube.
        $items = $order->items
            ->filter(fn ($item): bool => $item->stock_movement_id !== null)
            ->map(fn ($item): array => [
                'sku' => $item->product?->sku,
                'warehouse_code' => $item->warehouse?->code,
                'quantity' => (string) $item->received_quantity,
                'unit_cost' => $item->base_unit_cost === null ? null : (string) $item->base_unit_cost,
                'serial_units' => $item->serial_units ?? [],
            ])
            ->values()
            ->all();

        if ($items === []) {
            return;
        }

        $this->outbox->record(
            eventType: 'purchase_order.received',
            aggregateType: 'purchase_order',
            aggregateId: $order->id,
            payload: [
                'document_number' => $order->document_number,
                'status' => $order->status,
                'supplier_name' => $order->supplier?->name,
                'purchase_currency' => $order->purchase_currency,
                'received_at' => $order->received_at?->toISOString(),
                'notes' => "Compra a proveedor: {$order->supplier?->name}",
                'items' => $items,
            ],
            idempotencyKey: $this->eventKey('purchase_order.received', 'purchase_order', $order->id, $order->updated_at),
        );
    }

    public function stockMovementCreated(StockMovement $movement): void
    {
        $this->recordStockMovement('stock_movement.created', $movement);
    }

    public function inventoryTransferCreated(InventoryTransfer $transfer): void
    {
        $this->recordInventoryTransfer('inventory_transfer.created', $transfer);
    }

    public function inventoryTransferUpdated(InventoryTransfer $transfer): void
    {
        $this->recordInventoryTransfer('inventory_transfer.updated', $transfer);
    }

    public function inventoryTransferRequestCreated(InventoryTransferRequest $request): void
    {
        $request->loadMissing(['items']);

        $this->outbox->record(
            eventType: 'inventory_transfer_request.created',
            aggregateType: 'inventory_transfer_request',
            aggregateId: $request->id,
            payload: $this->serializeTransferRequest($request),
            idempotencyKey: $this->eventKey('inventory_transfer_request.created', 'inventory_transfer_request', $request->id, $request->updated_at),
        );
    }

    public function inventoryTransferRequestAccepted(InventoryTransferRequest $request): void
    {
        $request->loadMissing(['items']);

        $this->outbox->record(
            eventType: 'inventory_transfer_request.accepted',
            aggregateType: 'inventory_transfer_request',
            aggregateId: $request->id,
            payload: $this->serializeTransferRequest($request),
            idempotencyKey: $this->eventKey('inventory_transfer_request.accepted', 'inventory_transfer_request', $request->id, $request->updated_at),
        );
    }

    public function inventoryTransferRequestRejected(InventoryTransferRequest $request): void
    {
        $this->outbox->record(
            eventType: 'inventory_transfer_request.rejected',
            aggregateType: 'inventory_transfer_request',
            aggregateId: $request->id,
            payload: $this->serializeTransferRequest($request),
            idempotencyKey: $this->eventKey('inventory_transfer_request.rejected', 'inventory_transfer_request', $request->id, $request->updated_at),
        );
    }

    public function inventoryTransferRequestCancelled(InventoryTransferRequest $request): void
    {
        $this->outbox->record(
            eventType: 'inventory_transfer_request.cancelled',
            aggregateType: 'inventory_transfer_request',
            aggregateId: $request->id,
            payload: $this->serializeTransferRequest($request),
            idempotencyKey: $this->eventKey('inventory_transfer_request.cancelled', 'inventory_transfer_request', $request->id, $request->updated_at),
        );
    }

    private function serializeTransferRequest(InventoryTransferRequest $request): array
    {
        return [
            'id' => $request->id,
            'document_number' => $request->document_number,
            'sequence' => $request->sequence,
            'origin_tenant_id' => $request->origin_tenant_id,
            'destination_tenant_id' => $request->destination_tenant_id,
            'from_warehouse_id' => $request->from_warehouse_id,
            'destination_warehouse_id' => $request->destination_warehouse_id,
            'status' => $request->status,
            'reason' => $request->reason,
            'reference' => $request->reference,
            'notes' => $request->notes,
            'response_notes' => $request->response_notes,
            'requested_by' => $request->requested_by,
            'responded_by' => $request->responded_by,
            'requested_at' => $request->requested_at?->toISOString(),
            'responded_at' => $request->responded_at?->toISOString(),
            'completed_at' => $request->completed_at?->toISOString(),
            'items' => $request->items->map(fn (InventoryTransferRequestItem $item): array => [
                'id' => $item->id,
                'origin_product_id' => $item->origin_product_id,
                'destination_product_id' => $item->destination_product_id,
                'quantity' => (string) $item->quantity,
                'product_unit_ids' => $item->product_unit_ids ?? [],
                'serial_units' => $item->serial_units ?? [],
                'out_stock_movement_id' => $item->out_stock_movement_id,
                'in_stock_movement_id' => $item->in_stock_movement_id,
            ])->values()->all(),
        ];
    }

    public function productUnitUpdated(ProductUnit $unit): void
    {
        $this->recordProductUnit('product_unit.updated', $unit);
    }

    /**
     * Serializa una ProductImage (y sus variantes) para sync.
     * El payload incluye:
     *  - uuid: id publico unico (se conserva entre nodos via sha256 + tenant).
     *  - cloud_url: URL publica del archivo (relativa al APP_URL del cloud).
     *  - variants: {thumb, medium, original} con su cloud_url individual.
     *  - sha256: para que el local verifique integridad al descargar.
     *
     * Si `includeDeleted` es true (evento *.deleted), omite los campos pesados
     * y solo manda uuid + tenant_id + product_id.
     */
    private function recordProductImage(string $eventType, ProductImage $image, bool $includeDeleted): void
    {
        $cloudBase = rtrim((string) config('app.url'), '/');

        if ($includeDeleted) {
            $payload = [
                'uuid' => $image->uuid,
                'product_id' => $image->product_id,
            ];
            $this->outbox->record(
                eventType: $eventType,
                aggregateType: 'product_image',
                aggregateId: (int) ($image->id ?? crc32($image->uuid)),
                payload: $payload,
                idempotencyKey: $this->eventKey($eventType, 'product_image', $image->id, $image->updated_at ?? $image->deleted_at),
            );

            return;
        }

        $variants = $image->relationLoaded('variants') ? $image->variants : $image->variants()->get();
        $variantMap = [];
        foreach ($variants as $variant) {
            $variantMap[$variant->variant] = [
                'cloud_url' => "{$cloudBase}/storage/{$variant->storage_path}",
                'size' => (int) $variant->size,
                'mime' => $variant->mime,
                'width' => (int) $variant->width,
                'height' => (int) $variant->height,
            ];
        }

        $payload = [
            'uuid' => $image->uuid,
            'product_id' => $image->product_id,
            'cloud_url' => "{$cloudBase}/storage/{$image->storage_path}",
            'mime' => $image->mime,
            'size' => (int) $image->size,
            'width' => (int) $image->width,
            'height' => (int) $image->height,
            'sha256' => $image->sha256,
            'alt' => $image->alt,
            'sort' => (int) $image->sort,
            'is_primary' => (bool) $image->is_primary,
            'variants' => $variantMap,
        ];

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'product_image',
            aggregateId: (int) ($image->id ?? crc32($image->uuid)),
            payload: $payload,
            idempotencyKey: $this->eventKey($eventType, 'product_image', $image->id, $image->updated_at),
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
            idempotencyKey: $this->eventKey($eventType, 'product', $product->id, $product->updated_at),
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
            idempotencyKey: $this->eventKey($eventType, 'price_list', $priceList->id, $priceList->updated_at),
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
            idempotencyKey: $this->eventKey($eventType, 'product_price', $productPrice->id, $productPrice->updated_at),
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
            idempotencyKey: $this->eventKey($eventType, 'customer', $customer->id, $customer->updated_at),
        );
    }

    private function recordStockMovement(string $eventType, StockMovement $movement): void
    {
        $movement->loadMissing(['product', 'warehouse']);

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'stock_movement',
            aggregateId: $movement->id,
            payload: [
                'source_id' => $movement->id,
                'sku' => $movement->product?->sku,
                'warehouse_code' => $movement->warehouse?->code,
                'type' => $movement->type,
                'quantity' => (string) $movement->quantity,
                'unit_cost' => $movement->unit_cost === null ? null : (string) $movement->unit_cost,
                'reason' => $movement->reason,
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
                'created_at' => $movement->created_at?->toISOString(),
            ],
            idempotencyKey: $this->eventKey($eventType, 'stock_movement', $movement->id, $movement->updated_at),
        );
    }

    private function recordInventoryTransfer(string $eventType, InventoryTransfer $transfer): void
    {
        $transfer->loadMissing([
            'fromWarehouse:id,code,name',
            'toWarehouse:id,code,name',
            'items.product:id,sku',
        ]);

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'inventory_transfer',
            aggregateId: $transfer->id,
            payload: [
                'id' => $transfer->id,
                'document_number' => $transfer->document_number,
                'guide_number' => $transfer->guide_number,
                'type' => $transfer->type,
                'validation_mode' => $transfer->validation_mode,
                'status' => $transfer->status,
                'resolution_status' => $transfer->resolution_status,
                'from_warehouse_code' => $transfer->fromWarehouse?->code,
                'to_warehouse_code' => $transfer->toWarehouse?->code,
                'reason' => $transfer->reason,
                'reference' => $transfer->reference,
                'notes' => $transfer->notes,
                'processed_at' => $transfer->processed_at?->toISOString(),
                'prepared_at' => $transfer->prepared_at?->toISOString(),
                'dispatched_at' => $transfer->dispatched_at?->toISOString(),
                'received_at' => $transfer->received_at?->toISOString(),
                'cancelled_at' => $transfer->cancelled_at?->toISOString(),
                'resolved_at' => $transfer->resolved_at?->toISOString(),
                'items' => $transfer->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'sku' => $item->product?->sku,
                    'quantity' => (string) $item->quantity,
                    'requested_quantity' => $item->requested_quantity === null ? null : (string) $item->requested_quantity,
                    'prepared_quantity' => $item->prepared_quantity === null ? null : (string) $item->prepared_quantity,
                    'received_quantity' => $item->received_quantity === null ? null : (string) $item->received_quantity,
                    'difference_quantity' => $item->difference_quantity === null ? null : (string) $item->difference_quantity,
                ])->values()->all(),
            ],
            idempotencyKey: $this->eventKey($eventType, 'inventory_transfer', $transfer->id, $transfer->updated_at),
        );
    }

    private function recordProductUnit(string $eventType, ProductUnit $unit): void
    {
        $unit->loadMissing(['product', 'warehouse']);

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'product_unit',
            aggregateId: $unit->id,
            payload: [
                'sku' => $unit->product?->sku,
                'warehouse_code' => $unit->warehouse?->code,
                'serial_type' => $unit->serial_type,
                'serial_number' => $unit->serial_number,
                'status' => $unit->status,
            ],
            idempotencyKey: $this->eventKey($eventType, 'product_unit', $unit->id, $unit->updated_at),
        );
    }

    public static function eventKey(string $eventType, string $aggregateType, ?int $aggregateId, int|CarbonInterface|null $version = null): string
    {
        if ($version instanceof CarbonInterface) {
            $version = (int) ($version->getTimestamp() * 1_000_000) + (int) $version->micro;
        }

        return implode(':', [
            $eventType,
            $aggregateType,
            $aggregateId ?? 'none',
            $version ?? 0,
        ]);
    }
}
