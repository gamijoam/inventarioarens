<?php

namespace App\Modules\Sync\Services;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Models\AccountsPayablePayment;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequestItem;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\ProductEntries\Models\ProductEntry;
use App\Modules\ProductExits\Models\ProductExit;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductPrice;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Tag;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\PurchaseReturns\Models\PurchaseReturn;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\Services\CompanySettings;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Warranties\Models\WarrantyPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SyncCatalogOutboxService
{
    public function __construct(private readonly SyncOutboxService $outbox) {}

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

    public function variantCreated(ProductVariant $variant): void
    {
        $this->recordVariant('product_variant.created', $variant);
    }

    public function variantUpdated(ProductVariant $variant): void
    {
        $this->recordVariant('product_variant.updated', $variant);
    }

    public function variantDeleted(ProductVariant $variant): void
    {
        $this->recordVariant('product_variant.deleted', $variant);
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

    public function brandCreated(Brand $brand): void
    {
        $this->recordBrand('brand.created', $brand);
    }

    public function brandUpdated(Brand $brand): void
    {
        $this->recordBrand('brand.updated', $brand);
    }

    public function brandDeleted(Brand $brand): void
    {
        $this->recordBrand('brand.deleted', $brand);
    }

    public function categoryCreated(Category $category): void
    {
        $this->recordCategory('category.created', $category);
    }

    public function categoryUpdated(Category $category): void
    {
        $this->recordCategory('category.updated', $category);
    }

    public function categoryDeleted(Category $category): void
    {
        $this->recordCategory('category.deleted', $category);
    }

    public function tagCreated(Tag $tag): void
    {
        $this->recordTag('tag.created', $tag);
    }

    public function tagUpdated(Tag $tag): void
    {
        $this->recordTag('tag.updated', $tag);
    }

    public function tagDeleted(Tag $tag): void
    {
        $this->recordTag('tag.deleted', $tag);
    }

    public function paymentMethodCreated(PaymentMethod $method): void
    {
        $this->recordPaymentMethod('payment_method.created', $method);
    }

    public function paymentMethodUpdated(PaymentMethod $method): void
    {
        $this->recordPaymentMethod('payment_method.updated', $method);
    }

    public function warrantyPolicyCreated(WarrantyPolicy $policy): void
    {
        $this->recordWarrantyPolicy('warranty_policy.created', $policy);
    }

    public function warrantyPolicyUpdated(WarrantyPolicy $policy): void
    {
        $this->recordWarrantyPolicy('warranty_policy.updated', $policy);
    }

    public function promotionCreated(Promotion $promotion): void
    {
        $this->recordPromotion('promotion.created', $promotion);
    }

    public function promotionUpdated(Promotion $promotion): void
    {
        $this->recordPromotion('promotion.updated', $promotion);
    }

    public function promotionDeleted(Promotion $promotion): void
    {
        $this->recordPromotion('promotion.deleted', $promotion);
    }

    public function supplierCreated(Supplier $supplier): void
    {
        $this->recordSupplier('supplier.created', $supplier);
    }

    public function supplierUpdated(Supplier $supplier): void
    {
        $this->recordSupplier('supplier.updated', $supplier);
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

    public function accountsPayableCreated(AccountsPayable $payable): void
    {
        $this->recordAccountsPayable('accounts_payable.created', $payable);
    }

    public function accountsPayableUpdated(AccountsPayable $payable): void
    {
        $this->recordAccountsPayable('accounts_payable.updated', $payable);
    }

    public function accountsPayablePaymentCreated(AccountsPayablePayment $payment): void
    {
        $payment->loadMissing(['account', 'exchangeRateType']);

        $this->outbox->record(
            eventType: 'accounts_payable.payment_registered',
            aggregateType: 'accounts_payable_payment',
            aggregateId: $payment->id,
            payload: [
                'payable_document' => $payment->account?->document_number,
                'payment' => [
                    'id' => $payment->id,
                    'amount' => (string) $payment->amount,
                    'amount_base' => (string) $payment->amount_base,
                    'amount_local' => (string) $payment->amount_local,
                    'payment_currency' => $payment->payment_currency,
                    'exchange_rate_type_code' => $payment->exchange_rate_type_code,
                    'exchange_rate' => $payment->exchange_rate === null ? null : (string) $payment->exchange_rate,
                    'method' => $payment->method,
                    'reference' => $payment->reference,
                    'paid_at' => $payment->paid_at?->toISOString(),
                ],
            ],
            idempotencyKey: $this->eventKey('accounts_payable.payment_registered', 'accounts_payable_payment', $payment->id, $payment->updated_at),
        );
    }

    public function accountsReceivableCreated(AccountsReceivable $receivable): void
    {
        $this->recordAccountsReceivable('accounts_receivable.created', $receivable);
    }

    public function accountsReceivableUpdated(AccountsReceivable $receivable): void
    {
        $this->recordAccountsReceivable('accounts_receivable.updated', $receivable);
    }

    /**
     * Emite el evento de venta confirmada (módulo Sales puro, fuera de POS).
     * Las ventas del POS viajan con `pos.order.*` (sale embebido), por lo que
     * este evento se emite solo para ventas sin PosOrder. Incluye la identidad
     * de sync (sale_id + node) para que la nube haga upsert por sync_source.
     */
    public function saleConfirmed(Sale $sale): void
    {
        $sale->loadMissing([
            'customer',
            'items.product',
            'items.variant',
            'items.warehouse',
            'items.priceList',
            'promotionApplications.items',
        ]);

        $this->outbox->record(
            eventType: 'sale.confirmed',
            aggregateType: 'sale',
            aggregateId: $sale->id,
            payload: [
                'sale_id' => $sale->id,
                'status' => $sale->status,
                'customer_document_type' => $sale->customer?->document_type,
                'customer_document_number' => $sale->customer?->document_number,
                'total_base_amount' => (string) $sale->total_base_amount,
                'total_local_amount' => (string) $sale->total_local_amount,
                'confirmed_at' => $sale->confirmed_at?->toISOString(),
                'cancelled_at' => $sale->cancelled_at?->toISOString(),
                'items' => $sale->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'sku' => $item->product?->sku,
                    'warehouse_code' => $item->warehouse?->code,
                    'product_variant_sku' => $item->variant?->sku_variant,
                    'product_variant_color' => $item->variant?->color,
                    'price_list_code' => $item->priceList?->code,
                    'quantity' => (string) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'base_unit_price' => (string) $item->base_unit_price,
                    'base_total_amount' => (string) $item->base_total_amount,
                    'total_amount' => (string) $item->total_amount,
                    'sale_currency' => $item->sale_currency,
                    'exchange_rate_type_code' => $item->exchange_rate_type_code,
                    'exchange_rate' => $item->exchange_rate === null ? null : (string) $item->exchange_rate,
                    'discount_type' => $item->discount_type,
                    'discount_value' => $item->discount_value === null ? null : (string) $item->discount_value,
                    'discount_amount' => $item->discount_amount === null ? null : (string) $item->discount_amount,
                    'promotion_code' => $item->promotion_code,
                    'promotion_name' => $item->promotion_name,
                    'promotion_benefit_type' => $item->promotion_benefit_type,
                    'product_unit_ids' => $item->product_unit_ids ?? [],
                ])->values()->all(),
                'promotion_applications' => $sale->promotionApplications->map(fn ($application): array => [
                    'slot' => $application->slot,
                    'scope' => $application->scope,
                    'status' => $application->status,
                    'instance_uuid' => $application->instance_uuid,
                    'promotion_code' => $application->promotion_code,
                    'promotion_name' => $application->promotion_name,
                    'benefit_type' => $application->benefit_type,
                    'payment_currency' => $application->payment_currency,
                    'price_usd' => $application->price_usd,
                    'discount_percent' => $application->discount_percent,
                    'discount_amount_usd' => $application->discount_amount_usd,
                    'conditions_snapshot' => $application->conditions_snapshot,
                    'base_before_amount' => $application->base_before_amount,
                    'local_before_amount' => $application->local_before_amount,
                    'base_adjustment_amount' => $application->base_adjustment_amount,
                    'local_adjustment_amount' => $application->local_adjustment_amount,
                    'base_after_amount' => $application->base_after_amount,
                    'local_after_amount' => $application->local_after_amount,
                    'requested_at' => $application->requested_at?->toJSON(),
                    'validated_at' => $application->validated_at?->toJSON(),
                    'rejected_at' => $application->rejected_at?->toJSON(),
                    'created_at' => $application->created_at?->toJSON(),
                    'updated_at' => $application->updated_at?->toJSON(),
                    'items' => $application->items->map(fn ($item): array => [
                        'sale_item_id' => $item->sale_item_id,
                        'quantity' => $item->quantity,
                        'base_before_amount' => $item->base_before_amount,
                        'local_before_amount' => $item->local_before_amount,
                        'base_adjustment_amount' => $item->base_adjustment_amount,
                        'local_adjustment_amount' => $item->local_adjustment_amount,
                        'base_after_amount' => $item->base_after_amount,
                        'local_after_amount' => $item->local_after_amount,
                        'created_at' => $item->created_at?->toJSON(),
                        'updated_at' => $item->updated_at?->toJSON(),
                    ])->values()->all(),
                ])->values()->all(),
            ],
            idempotencyKey: $this->eventKey('sale.confirmed', 'sale', $sale->id, $sale->updated_at),
        );
    }

    /**
     * Emite el estado completo de un usuario dentro de un tenant: datos del
     * user (email/name/password hash), su status de membresia y los roles
     * asignados en ese tenant. Es el mecanismo para que cambios de permisos
     * y accesos viajen entre local y nube (antes no viajaban).
     *
     * El password hash viaja para que el usuario pueda autenticarse en el nodo
     * destino sin reingresar credenciales. Los roles viajan por nombre; el
     * applier resuelve/crea el rol por (tenant_id, name) y sincroniza.
     */
    public function userRolesSynced(User $user, Tenant $tenant): void
    {
        $pivotStatus = DB::table('tenant_user')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->value('status');

        $roleNames = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.tenant_id', $tenant->id)
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', get_class($user))
            ->pluck('roles.name')
            ->values()
            ->all();

        $this->outbox->record(
            eventType: 'user.roles.synced',
            aggregateType: 'user',
            aggregateId: $user->id,
            payload: [
                'email' => $user->email,
                'name' => $user->name,
                'password_hash' => $user->getRawOriginal('password'),
                'is_platform_admin' => (bool) $user->is_platform_admin,
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'is_active' => $pivotStatus === 'active',
                'roles' => $roleNames,
                'user_updated_at' => $user->updated_at?->toISOString(),
            ],
            idempotencyKey: $this->eventKey('user.roles.synced', 'user', $user->id, $user->updated_at),
        );
    }

    public function branchCreated(Branch $branch): void
    {
        $this->recordBranch('branch.created', $branch);
    }

    public function branchUpdated(Branch $branch): void
    {
        $this->recordBranch('branch.updated', $branch);
    }

    public function warehouseCreated(Warehouse $warehouse): void
    {
        $this->recordWarehouse('warehouse.created', $warehouse);
    }

    public function warehouseUpdated(Warehouse $warehouse): void
    {
        $this->recordWarehouse('warehouse.updated', $warehouse);
    }

    private function recordBranch(string $eventType, Branch $branch): void
    {
        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'branch',
            aggregateId: $branch->id,
            payload: [
                'code' => $branch->code,
                'name' => $branch->name,
                'status' => $branch->status,
            ],
            idempotencyKey: $this->eventKey($eventType, 'branch', $branch->id, $branch->updated_at),
        );
    }

    private function recordWarehouse(string $eventType, Warehouse $warehouse): void
    {
        $warehouse->loadMissing('branch');

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'warehouse',
            aggregateId: $warehouse->id,
            payload: [
                'code' => $warehouse->code,
                'name' => $warehouse->name,
                'status' => $warehouse->status,
                'branch_code' => $warehouse->branch?->code,
            ],
            idempotencyKey: $this->eventKey($eventType, 'warehouse', $warehouse->id, $warehouse->updated_at),
        );
    }

    public function cashRegisterCreated(CashRegister $register): void
    {
        $this->recordCashRegister('cash_register.created', $register);
    }

    public function cashRegisterUpdated(CashRegister $register): void
    {
        $this->recordCashRegister('cash_register.updated', $register);
    }

    private function recordCashRegister(string $eventType, CashRegister $register): void
    {
        $register->loadMissing('branch');

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'cash_register',
            aggregateId: $register->id,
            payload: [
                'code' => $register->code,
                'name' => $register->name,
                'status' => $register->status,
                'notes' => $register->notes,
                'branch_code' => $register->branch?->code,
            ],
            idempotencyKey: $this->eventKey($eventType, 'cash_register', $register->id, $register->updated_at),
        );
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
        $order->loadMissing(['supplier', 'items.product', 'items.productVariant', 'items.warehouse']);
        $documentNumber = $this->purchaseDocumentNumber($order);

        $this->outbox->record(
            eventType: 'purchase_order.created',
            aggregateType: 'purchase_order',
            aggregateId: $order->id,
            payload: [
                'document_number' => $documentNumber,
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
                    'product_variant_sku' => $item->productVariant?->sku_variant,
                    'product_variant_color' => $item->productVariant?->color,
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
        $order->loadMissing(['supplier', 'items.product', 'items.productVariant', 'items.warehouse', 'items.stockMovement']);
        $documentNumber = $this->purchaseDocumentNumber($order);

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
                'product_variant_sku' => $item->productVariant?->sku_variant,
                'product_variant_color' => $item->productVariant?->color,
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
                'document_number' => $documentNumber,
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

    /**
     * Emite `purchase_return.created` (devolucion de compra al proveedor).
     * La devolucion decrementa stock en el nodo origen (salida de mercancia);
     * el applier replica esa salida en el nodo destino.
     */
    public function purchaseReturnCreated(PurchaseReturn $return): void
    {
        $return->loadMissing([
            'purchaseOrder',
            'items.product',
            'items.warehouse',
            'items.stockMovement',
        ]);

        $this->outbox->record(
            eventType: 'purchase_return.created',
            aggregateType: 'purchase_return',
            aggregateId: $return->id,
            payload: [
                'return_id' => $return->id,
                'purchase_order_document' => $return->purchaseOrder?->document_number,
                'status' => $return->status,
                'reason' => $return->reason,
                'processed_at' => $return->processed_at?->toISOString(),
                'items' => $return->items->map(fn ($item): array => [
                    'sku' => $item->product?->sku,
                    'warehouse_code' => $item->warehouse?->code,
                    'quantity' => (string) $item->quantity,
                    'product_serial_units' => collect($item->product_unit_ids ?? [])
                        ->map(function (int $unitId): ?array {
                            $unit = $this->productUnitBy($unitId);

                            return $unit ? [
                                'serial_type' => $unit['serial_type'],
                                'serial_number' => $unit['serial_number'],
                            ] : null;
                        })
                        ->filter()
                        ->values()
                        ->all(),
                    'reason' => $item->reason,
                ])->values()->all(),
            ],
            idempotencyKey: $this->eventKey('purchase_return.created', 'purchase_return', $return->id, $return->updated_at),
        );
    }

    private function productUnitBy(int $unitId): ?array
    {
        $unit = DB::table('product_units')->where('id', $unitId)->first();

        return $unit ? [
            'serial_type' => $unit->serial_type,
            'serial_number' => $unit->serial_number,
        ] : null;
    }

    private function purchaseDocumentNumber(PurchaseOrder $order): string
    {
        $documentNumber = trim((string) $order->document_number);

        return $documentNumber !== ''
            ? $documentNumber
            : 'COMPRA-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
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

    /**
     * Emite la seccion `company` de tenant_settings para que el nodo local
     * refleje la identidad fiscal de la empresa (razon social, RIF, etc.)
     * en sus tickets/guias. Solo se propaga la seccion company; el resto de
     * settings (telegram, etc.) es local a cada nodo.
     */
    public function tenantSettingsUpdated(Tenant $tenant, ?TenantSetting $setting = null): void
    {
        $setting ??= $tenant->setting;
        $company = CompanySettings::getForTenant($tenant);

        $this->outbox->record(
            eventType: 'tenant_settings.updated',
            aggregateType: 'tenant_settings',
            aggregateId: $tenant->id,
            payload: [
                'tenant_id' => $tenant->id,
                'company' => $company,
            ],
            idempotencyKey: $this->eventKey('tenant_settings.updated', 'tenant_settings', $tenant->id, $setting?->updated_at),
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
            'flow_type' => $request->flow_type ?? InventoryTransferRequest::FLOW_STOCK_REQUEST,
            'initiated_by_tenant_id' => $request->initiated_by_tenant_id ?? $request->origin_tenant_id,
            'sender_tenant_id' => $request->sender_tenant_id ?? $request->destination_tenant_id,
            'receiver_tenant_id' => $request->receiver_tenant_id ?? $request->origin_tenant_id,
            'from_warehouse_id' => $request->from_warehouse_id,
            'destination_warehouse_id' => $request->destination_warehouse_id,
            'sender_warehouse_id' => $request->sender_warehouse_id ?? $request->destination_warehouse_id,
            'receiver_warehouse_id' => $request->receiver_warehouse_id ?? $request->from_warehouse_id,
            'status' => $request->status,
            'logistics_mode' => (bool) $request->logistics_mode,
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
                'product_variant_id' => $item->product_variant_id,
                'product_variant_sku' => $item->productVariant?->sku_variant,
                'product_variant_color' => $item->productVariant?->color,
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
     * y solo manda uuid + product_sku + product_id legacy.
     */
    private function recordProductImage(string $eventType, ProductImage $image, bool $includeDeleted): void
    {
        // Base pública para cloud_url: en la NUBE es el APP_URL; en los nodos
        // locales debe apuntar a la nube (SYNC_PUBLIC_BASE) para que la imagen
        // sea descargable desde allí. Sin esto, un nodo local emite
        // http://localhost/... y la nube no puede bajar el archivo.
        $cloudBase = rtrim((string) (config('services.sync.public_base') ?: config('app.url')), '/');
        $productSku = $image->product?->sku
            ?? Product::query()->whereKey($image->product_id)->value('sku');

        if ($includeDeleted) {
            $payload = [
                'uuid' => $image->uuid,
                'product_sku' => $productSku,
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
            $variantCloudPath = $variant->cloud_storage_path ?: $variant->storage_path;
            $variantMap[$variant->variant] = [
                'cloud_url' => "{$cloudBase}/storage/{$variantCloudPath}",
                'size' => (int) $variant->size,
                'mime' => $variant->mime,
                'width' => (int) $variant->width,
                'height' => (int) $variant->height,
            ];
        }

        // cloud_storage_path es la ruta REAL asignada por la nube (tenant cloud).
        // storage_path en un nodo local usa el tenant local y no coincide con la
        // ruta del archivo en la nube; usarlo romperia la URL descargable.
        $imageCloudPath = $image->cloud_storage_path ?: $image->storage_path;
        $payload = [
            'uuid' => $image->uuid,
            'product_sku' => $productSku,
            'product_id' => $image->product_id,
            'cloud_url' => "{$cloudBase}/storage/{$imageCloudPath}",
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
        $product->loadMissing(['saleExchangeRateType', 'warrantyPolicy', 'brand', 'categories', 'tags']);

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'product',
            aggregateId: $product->id,
            payload: [
                'sku' => $product->sku,
                'name' => $product->name,
                'barcode' => $product->barcode,
                'description' => $product->description,
                'long_description' => $product->long_description,
                'tracking_type' => $product->tracking_type,
                'unit_of_measure' => $product->unit_of_measure,
                'track_stock' => (bool) $product->track_stock,
                'base_price' => $product->base_price === null ? null : (string) $product->base_price,
                'profit_margin' => $product->profit_margin === null ? null : (string) $product->profit_margin,
                'pricing_mode' => $product->pricing_mode ?? Product::PRICING_AUTOMATIC,
                'sale_currency' => $product->sale_currency,
                'sale_exchange_rate_type_id' => $product->sale_exchange_rate_type_id,
                'sale_exchange_rate_type_code' => $product->saleExchangeRateType?->code,
                'image_url' => $product->image_url,
                'brand_slug' => $product->brand?->slug,
                'category_slugs' => $product->categories->pluck('slug')->values()->all(),
                'tag_slugs' => $product->tags->pluck('slug')->values()->all(),
                'warranty_policy_id' => $product->warranty_policy_id,
                'warranty_policy_name' => $product->warrantyPolicy?->name,
                'warranty_policy_duration_days' => $product->warrantyPolicy?->duration_days,
                'warranty_policy_coverage_type' => $product->warrantyPolicy?->coverage_type,
                'warranty_policy_conditions' => $product->warrantyPolicy?->conditions,
                'warranty_policy_is_active' => $product->warrantyPolicy ? (bool) $product->warrantyPolicy->is_active : null,
                'min_stock' => $product->min_stock === null ? null : (string) $product->min_stock,
                'max_stock' => $product->max_stock === null ? null : (string) $product->max_stock,
                'reorder_quantity' => $product->reorder_quantity === null ? null : (string) $product->reorder_quantity,
                'is_catalog_active' => (bool) ($product->is_catalog_active ?? true),
                'is_active' => (bool) $product->is_active,
            ],
            idempotencyKey: $this->eventKey($eventType, 'product', $product->id, $product->updated_at),
        );
    }

    private function recordPriceList(string $eventType, PriceList $priceList): void
    {
        $priceList->loadMissing(['paymentMethods', 'paymentExchangeRateType']);

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'price_list',
            aggregateId: $priceList->id,
            payload: [
                'code' => $priceList->code,
                'name' => $priceList->name,
                'description' => $priceList->description,
                'markup_percentage' => $priceList->markup_percentage === null ? null : (string) $priceList->markup_percentage,
                'is_default' => (bool) $priceList->is_default,
                'is_active' => (bool) $priceList->is_active,
                'sort_order' => (int) $priceList->sort_order,
                'payment_exchange_rate_type_code' => $priceList->paymentExchangeRateType?->code,
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

    private function recordBrand(string $eventType, Brand $brand): void
    {
        $this->outbox->record($eventType, 'brand', $brand->id, [
            'slug' => $brand->slug, 'name' => $brand->name, 'description' => $brand->description,
            'is_active' => $eventType !== 'brand.deleted' && (bool) $brand->is_active,
            '_deleted' => $eventType === 'brand.deleted',
        ], $this->eventKey($eventType, 'brand', $brand->id, $brand->updated_at));
    }

    private function recordCategory(string $eventType, Category $category): void
    {
        $category->loadMissing('parent');
        $this->outbox->record($eventType, 'category', $category->id, [
            'slug' => $category->slug, 'name' => $category->name, 'description' => $category->description,
            'sort_order' => (int) $category->sort_order, 'is_active' => $eventType !== 'category.deleted' && (bool) $category->is_active,
            '_deleted' => $eventType === 'category.deleted',
            'parent_slug' => $category->parent?->slug,
        ], $this->eventKey($eventType, 'category', $category->id, $category->updated_at));
    }

    private function recordTag(string $eventType, Tag $tag): void
    {
        $this->outbox->record($eventType, 'tag', $tag->id, [
            'slug' => $tag->slug, 'name' => $tag->name, 'color' => $tag->color,
            '_deleted' => $eventType === 'tag.deleted',
        ], $this->eventKey($eventType, 'tag', $tag->id, $tag->updated_at));
    }

    private function recordPaymentMethod(string $eventType, PaymentMethod $method): void
    {
        $this->outbox->record($eventType, 'payment_method', $method->id, [
            'code' => $method->code, 'name' => $method->name, 'method' => $method->method,
            'currency_mode' => $method->currency_mode, 'requires_reference' => (bool) $method->requires_reference,
            'is_active' => (bool) $method->is_active, 'sort_order' => (int) $method->sort_order,
        ], $this->eventKey($eventType, 'payment_method', $method->id, $method->updated_at));
    }

    private function recordWarrantyPolicy(string $eventType, WarrantyPolicy $policy): void
    {
        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'warranty_policy',
            aggregateId: $policy->id,
            payload: [
                'name' => $policy->name,
                'duration_days' => $policy->duration_days,
                'coverage_type' => $policy->coverage_type,
                'conditions' => $policy->conditions,
                'is_active' => (bool) $policy->is_active,
            ],
            idempotencyKey: $this->eventKey($eventType, 'warranty_policy', $policy->id, $policy->updated_at),
        );
    }

    private function recordPromotion(string $eventType, Promotion $promotion): void
    {
        $promotion->loadMissing('items.product');

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'promotion',
            aggregateId: $promotion->id,
            payload: [
                'id' => $promotion->id,
                'name' => $promotion->name,
                'code' => $promotion->code,
                'scope' => $promotion->scope,
                'allows_combos' => (bool) $promotion->allows_combos,
                'benefit_type' => $promotion->benefit_type,
                'price_currency' => $promotion->price_currency,
                'payment_currency' => $promotion->payment_currency,
                'price_usd' => $promotion->price_usd === null ? null : (string) $promotion->price_usd,
                'discount_percent' => $promotion->discount_percent === null ? null : (string) $promotion->discount_percent,
                'discount_amount_usd' => $promotion->discount_amount_usd === null ? null : (string) $promotion->discount_amount_usd,
                'priority' => (int) $promotion->priority,
                'is_active' => $eventType !== 'promotion.deleted' && (bool) $promotion->is_active,
                'starts_at' => $promotion->starts_at?->toISOString(),
                'ends_at' => $promotion->ends_at?->toISOString(),
                '_deleted' => $eventType === 'promotion.deleted',
                'items' => $promotion->items->map(fn ($item): array => [
                    'product_sku' => $item->product?->sku,
                    'quantity' => (string) $item->quantity,
                    'item_role' => $item->item_role,
                    'sort_order' => (int) $item->sort_order,
                ])->values()->all(),
            ],
            idempotencyKey: $this->eventKey($eventType, 'promotion', $promotion->id, $promotion->updated_at),
        );
    }

    private function recordSupplier(string $eventType, Supplier $supplier): void
    {
        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'supplier',
            aggregateId: $supplier->id,
            payload: [
                'name' => $supplier->name,
                'document_type' => $supplier->document_type,
                'document_number' => $supplier->document_number,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
                'fiscal_address' => $supplier->fiscal_address,
                'notes' => $supplier->notes,
                'is_active' => (bool) $supplier->is_active,
            ],
            idempotencyKey: $this->eventKey($eventType, 'supplier', $supplier->id, $supplier->updated_at),
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

    private function recordAccountsPayable(string $eventType, AccountsPayable $payable): void
    {
        $payable->loadMissing(['supplier', 'purchaseOrder']);

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'accounts_payable',
            aggregateId: $payable->id,
            payload: [
                'document_number' => $payable->document_number,
                'purchase_order_id' => $payable->purchase_order_id,
                'purchase_order_document' => $payable->purchaseOrder?->document_number,
                'supplier_document' => $payable->supplier?->document_number,
                'supplier_name' => $payable->supplier?->name,
                'status' => $payable->status,
                'currency' => $payable->currency,
                'exchange_rate_type_code' => $payable->exchange_rate_type_code,
                'exchange_rate' => $payable->exchange_rate === null ? null : (string) $payable->exchange_rate,
                'original_base_amount' => (string) $payable->original_base_amount,
                'original_local_amount' => (string) $payable->original_local_amount,
                'returned_base_amount' => (string) $payable->returned_base_amount,
                'returned_local_amount' => (string) $payable->returned_local_amount,
                'paid_base_amount' => (string) $payable->paid_base_amount,
                'paid_local_amount' => (string) $payable->paid_local_amount,
                'adjusted_base_amount' => (string) $payable->adjusted_base_amount,
                'adjusted_local_amount' => (string) $payable->adjusted_local_amount,
                'balance_base_amount' => (string) $payable->balance_base_amount,
                'balance_local_amount' => (string) $payable->balance_local_amount,
                'due_date' => $payable->due_date?->toDateString(),
                'opened_at' => $payable->opened_at?->toISOString(),
                'paid_at' => $payable->paid_at?->toISOString(),
            ],
            idempotencyKey: $this->eventKey($eventType, 'accounts_payable', $payable->id, $payable->updated_at),
        );
    }

    private function recordAccountsReceivable(string $eventType, AccountsReceivable $receivable): void
    {
        $receivable->loadMissing('customer');

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'accounts_receivable',
            aggregateId: $receivable->id,
            payload: [
                'document_number' => $receivable->document_number,
                'sale_id' => $receivable->sale_id,
                'customer_document' => $receivable->customer?->document_number,
                'customer_name' => $receivable->customer?->name,
                'status' => $receivable->status,
                'currency' => $receivable->currency,
                'exchange_rate_type_code' => $receivable->exchange_rate_type_code,
                'exchange_rate' => $receivable->exchange_rate === null ? null : (string) $receivable->exchange_rate,
                'original_base_amount' => (string) $receivable->original_base_amount,
                'original_local_amount' => (string) $receivable->original_local_amount,
                'returned_base_amount' => (string) $receivable->returned_base_amount,
                'returned_local_amount' => (string) $receivable->returned_local_amount,
                'collected_base_amount' => (string) $receivable->collected_base_amount,
                'collected_local_amount' => (string) $receivable->collected_local_amount,
                'adjusted_base_amount' => (string) $receivable->adjusted_base_amount,
                'adjusted_local_amount' => (string) $receivable->adjusted_local_amount,
                'balance_base_amount' => (string) $receivable->balance_base_amount,
                'balance_local_amount' => (string) $receivable->balance_local_amount,
                'due_date' => $receivable->due_date?->toDateString(),
                'opened_at' => $receivable->opened_at?->toISOString(),
                'paid_at' => $receivable->paid_at?->toISOString(),
            ],
            idempotencyKey: $this->eventKey($eventType, 'accounts_receivable', $receivable->id, $receivable->updated_at),
        );
    }

    private function recordStockMovement(string $eventType, StockMovement $movement): void
    {
        $movement->loadMissing(['product', 'warehouse', 'variant']);

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
                'product_variant_sku' => $movement->variant?->sku_variant,
                'product_variant_color' => $movement->variant?->color,
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
            'items.productVariant:id,sku_variant,color',
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
                    'product_variant_id' => $item->product_variant_id,
                    'product_variant_sku' => $item->productVariant?->sku_variant,
                    'product_variant_color' => $item->productVariant?->color,
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

    private function recordVariant(string $eventType, ProductVariant $variant): void
    {
        $variant->loadMissing('product');

        $this->outbox->record(
            eventType: $eventType,
            aggregateType: 'product_variant',
            aggregateId: $variant->id,
            payload: [
                'product_sku' => $variant->product?->sku,
                'color' => $variant->color,
                'color_hex' => $variant->color_hex,
                'sku_variant' => $variant->sku_variant,
                'barcode_variant' => $variant->barcode_variant,
                'price_override' => $variant->price_override === null ? null : (string) $variant->price_override,
                'is_active' => (bool) $variant->is_active,
                'position' => (int) $variant->position,
            ],
            idempotencyKey: $this->eventKey($eventType, 'product_variant', $variant->id, $variant->updated_at),
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
