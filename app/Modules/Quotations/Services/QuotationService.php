<?php

namespace App\Modules\Quotations\Services;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\POS\Services\PosCheckoutService;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductPriceService;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Models\QuotationItem;
use App\Modules\Quotations\Resources\QuotationResource;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\CompanySettings;
use App\Modules\Warehouses\Models\Warehouse;
use Barryvdh\DomPDF\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;

class QuotationService
{
    public function __construct(
        private readonly ProductPriceService $prices,
        private readonly PosCheckoutService $posCheckout,
    ) {}

    public function create(User $user, array $data): Quotation
    {
        return DB::transaction(function () use ($user, $data): Quotation {
            $warehouse = isset($data['warehouse_id'])
                ? Warehouse::query()->findOrFail($data['warehouse_id'])
                : null;
            $items = $this->buildItems($data['items'], $warehouse);
            $totals = $this->totals($items);

            $sequence = $this->nextSequence();
            $status = $data['status'] ?? Quotation::STATUS_DRAFT;
            $issuedAt = $status === Quotation::STATUS_ISSUED ? now() : null;

            $quotation = Quotation::create([
                'sequence' => $sequence,
                'document_number' => 'COT-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $this->customerName($data),
                'warehouse_id' => $warehouse?->id,
                'status' => $status,
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'subtotal_base_amount' => $totals['subtotal_base'],
                'subtotal_local_amount' => $totals['subtotal_local'],
                'discount_base_amount' => $totals['discount_base'],
                'discount_local_amount' => $totals['discount_local'],
                'total_base_amount' => $totals['total_base'],
                'total_local_amount' => $totals['total_local'],
                'exchange_rate_type_id' => $totals['exchange_rate_type_id'],
                'exchange_rate_type_code' => $totals['exchange_rate_type_code'],
                'exchange_rate' => $totals['exchange_rate'],
                'created_by' => $user->id,
                'issued_at' => $issuedAt,
            ]);

            $this->persistItems($quotation, $items);

            return $quotation->load(['items', 'customer', 'warehouse', 'creator']);
        });
    }

    public function update(User $user, Quotation $quotation, array $data): Quotation
    {
        $this->assertEditable($quotation);

        return DB::transaction(function () use ($quotation, $data): Quotation {
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);

            $warehouse = isset($data['warehouse_id'])
                ? Warehouse::query()->findOrFail($data['warehouse_id'])
                : ($quotation->warehouse_id ? Warehouse::query()->findOrFail($quotation->warehouse_id) : null);

            if (array_key_exists('items', $data)) {
                $items = $this->buildItems($data['items'], $warehouse);
                $totals = $this->totals($items);
            } else {
                $totals = [
                    'subtotal_base' => (float) $quotation->subtotal_base_amount,
                    'subtotal_local' => (float) $quotation->subtotal_local_amount,
                    'discount_base' => (float) $quotation->discount_base_amount,
                    'discount_local' => (float) $quotation->discount_local_amount,
                    'total_base' => (float) $quotation->total_base_amount,
                    'total_local' => (float) $quotation->total_local_amount,
                    'exchange_rate_type_id' => $quotation->exchange_rate_type_id,
                    'exchange_rate_type_code' => $quotation->exchange_rate_type_code,
                    'exchange_rate' => $quotation->exchange_rate,
                ];
            }

            $nextStatus = $data['status'] ?? $quotation->status;
            if ($quotation->status === Quotation::STATUS_DRAFT && $nextStatus === Quotation::STATUS_ISSUED) {
                $quotation->issued_at = now();
            }

            $quotation->fill([
                'customer_id' => $data['customer_id'] ?? $quotation->customer_id,
                'customer_name' => $data['customer_name'] ?? ($data['customer_id'] ?? null ? null : $quotation->customer_name),
                'warehouse_id' => $warehouse?->id ?? $quotation->warehouse_id,
                'status' => $nextStatus,
                'valid_until' => $data['valid_until'] ?? $quotation->valid_until,
                'notes' => $data['notes'] ?? $quotation->notes,
                'subtotal_base_amount' => $totals['subtotal_base'],
                'subtotal_local_amount' => $totals['subtotal_local'],
                'discount_base_amount' => $totals['discount_base'],
                'discount_local_amount' => $totals['discount_local'],
                'total_base_amount' => $totals['total_base'],
                'total_local_amount' => $totals['total_local'],
                'exchange_rate_type_id' => $totals['exchange_rate_type_id'],
                'exchange_rate_type_code' => $totals['exchange_rate_type_code'],
                'exchange_rate' => $totals['exchange_rate'],
            ])->save();

            if (array_key_exists('items', $data)) {
                $quotation->items()->delete();
                $this->persistItems($quotation, $items);
            }

            return $quotation->load(['items', 'customer', 'warehouse', 'creator']);
        });
    }

    public function cancel(User $user, Quotation $quotation): Quotation
    {
        $this->assertEditable($quotation);

        $quotation->update(['status' => Quotation::STATUS_CANCELLED]);

        return $quotation->load(['items', 'customer']);
    }

    /**
     * Convierte una cotizacion emitida en una orden POS pendiente (estado
     * OPEN) para que el vendedor la cobre desde el terminal. Reusa el flujo
     * hold/armar del POS con los precios vigentes de la lista seleccionada.
     */
    public function convert(User $user, Quotation $quotation): array
    {
        if ($quotation->status !== Quotation::STATUS_ISSUED) {
            throw ValidationException::withMessages([
                'status' => 'Solo las cotizaciones emitidas pueden convertirse en venta.',
            ]);
        }

        $items = $quotation->items->map(fn (QuotationItem $item): array => [
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => (float) $item->quantity,
            'price_list_id' => $item->price_list_id,
            'warehouse_id' => $quotation->warehouse_id,
        ])->values()->all();

        $order = $this->posCheckout->holdOrder(
            seller: $user,
            items: $items,
            customerId: $quotation->customer_id,
            customerName: $quotation->customer_name,
        );

        $quotation->update([
            'status' => Quotation::STATUS_CONVERTED,
            'converted_at' => now(),
            'converted_pos_order_id' => $order->id,
        ]);

        return [
            'quotation' => QuotationResource::make($quotation->load(['items', 'customer'])),
            'pos_order' => $order,
        ];
    }

    public function renderHtml(Quotation $quotation): string
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->whereKey($quotation->tenant_id)->first();
        $company = $tenant ? CompanySettings::getForTenant($tenant) : CompanySettings::defaults();

        return View::make('quotations.quotation-pdf', [
            'quotation' => $quotation->loadMissing(['items', 'customer', 'warehouse']),
            'company' => $company,
            'show_company' => (bool) ($company['show_on']['quotation'] ?? true),
            'generatedAt' => now(),
        ])->render();
    }

    public function renderPdf(Quotation $quotation): string
    {
        $html = $this->renderHtml($quotation);

        /** @var ServiceProvider $dompdf */
        $dompdf = app('dompdf.wrapper');
        $dompdf->loadHTML($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function buildItems(array $items, ?Warehouse $warehouse): array
    {
        $built = [];

        foreach ($items as $index => $item) {
            $product = Product::query()->findOrFail($item['product_id']);
            $variantId = $item['product_variant_id'] ?? null;
            if ($variantId !== null) {
                $belongsToProduct = ProductVariant::query()
                    ->where('id', (int) $variantId)
                    ->where('product_id', $product->id)
                    ->exists();
                if (! $belongsToProduct) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_variant_id" => 'La variante seleccionada no pertenece al producto indicado.',
                    ]);
                }
            }

            $quote = $this->prices->quote($product, $item['price_list_id'] ?? null);
            $quantity = (float) $item['quantity'];
            $unitBase = (float) $quote['price_usd'];
            $unitLocal = (float) ($quote['price_ves'] ?? 0);

            $built[] = [
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'quantity' => $quantity,
                'price_list_id' => $quote['price_list_id'],
                'unit_price_base' => $unitBase,
                'unit_price_local' => $unitLocal,
                'discount_base' => 0,
                'discount_local' => 0,
                'total_base' => round($unitBase * $quantity, 4),
                'total_local' => round($unitLocal * $quantity, 4),
                'sort_order' => $index,
                'exchange_rate_type_id' => $quote['exchange_rate_type_id'] ?? null,
                'exchange_rate_type_code' => $quote['exchange_rate_type_code'] ?? null,
                'exchange_rate' => isset($quote['exchange_rate']) ? (float) $quote['exchange_rate'] : null,
            ];
        }

        return $built;
    }

    private function totals(array $items): array
    {
        $subtotalBase = 0.0;
        $subtotalLocal = 0.0;
        $rateTypeId = null;
        $rateTypeCode = null;
        $rate = null;

        foreach ($items as $item) {
            $subtotalBase += (float) $item['total_base'];
            $subtotalLocal += (float) $item['total_local'];
            if ($item['exchange_rate'] !== null && $rate === null) {
                $rateTypeId = $item['exchange_rate_type_id'];
                $rateTypeCode = $item['exchange_rate_type_code'];
                $rate = $item['exchange_rate'];
            }
        }

        $rate ??= ($subtotalBase > 0 ? round($subtotalLocal / $subtotalBase, 6) : null);

        return [
            'subtotal_base' => round($subtotalBase, 4),
            'subtotal_local' => round($subtotalLocal, 4),
            'discount_base' => 0,
            'discount_local' => 0,
            'total_base' => round($subtotalBase, 4),
            'total_local' => round($subtotalLocal, 4),
            'exchange_rate_type_id' => $rateTypeId,
            'exchange_rate_type_code' => $rateTypeCode,
            'exchange_rate' => $rate,
        ];
    }

    private function persistItems(Quotation $quotation, array $items): void
    {
        foreach ($items as $item) {
            QuotationItem::create(array_merge($item, ['quotation_id' => $quotation->id]));
        }
    }

    private function customerName(array $data): ?string
    {
        if (filled($data['customer_name'] ?? null)) {
            return $data['customer_name'];
        }

        if (filled($data['customer_id'] ?? null)) {
            $customer = Customer::query()->find((int) $data['customer_id']);

            return $customer?->name;
        }

        return null;
    }

    private function assertEditable(Quotation $quotation): void
    {
        if (in_array($quotation->status, [Quotation::STATUS_CONVERTED, Quotation::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Solo las cotizaciones en borrador o emitidas pueden editarse.',
            ]);
        }
    }

    private function nextSequence(): int
    {
        return ((int) Quotation::query()->orderByDesc('sequence')->lockForUpdate()->value('sequence')) + 1;
    }
}
