<?php

namespace App\Modules\Fiscal\Services;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\CompanySettings;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FiscalDocumentPreviewService
{
    public function createFromSale(Sale $sale, User $actor): array
    {
        $tenant = app(TenantManager::class)->require();

        return DB::transaction(function () use ($sale, $actor, $tenant): array {
            $source = Sale::query()
                ->with([
                    'customer',
                    'items.product',
                    'items.variant',
                    'items.warehouse',
                    'posOrder.cashRegisterSession.branch',
                ])
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($source->status !== Sale::STATUS_CONFIRMED) {
                throw ValidationException::withMessages([
                    'sale_id' => 'Solo se puede crear un borrador pre-fiscal desde una venta confirmada.',
                ]);
            }

            $existing = FiscalDocument::query()
                ->with('items')
                ->where('sale_id', $source->id)
                ->where('document_type', FiscalDocument::DOCUMENT_TYPE_INTERNAL_PREVIEW)
                ->first();

            if ($existing) {
                return [$existing, false];
            }

            $snapshotAt = now();
            $document = FiscalDocument::create([
                'sale_id' => $source->id,
                'document_type' => FiscalDocument::DOCUMENT_TYPE_INTERNAL_PREVIEW,
                'document_mode' => FiscalDocument::DOCUMENT_MODE_INTERNAL_PREVIEW,
                'status' => FiscalDocument::STATUS_PREVIEW,
                'company_snapshot' => $this->companySnapshot($tenant),
                'branch_snapshot' => $this->branchSnapshot($source->posOrder?->cashRegisterSession?->branch),
                'customer_snapshot' => $this->customerSnapshot($source->customer),
                'totals_snapshot' => $this->totalsSnapshot($source),
                'snapshot_at' => $snapshotAt,
                'created_by' => $actor->id,
            ]);

            foreach ($source->items as $item) {
                $document->items()->create($this->itemSnapshot($item));
            }

            return [$document->load('items'), true];
        });
    }

    private function companySnapshot(Tenant $tenant): array
    {
        $company = CompanySettings::getForTenant($tenant);

        return [
            'legal_name' => $company['razon_social'],
            'tax_id' => $company['rif'],
            'fiscal_address' => $company['domicilio_fiscal'],
            'city' => $company['ciudad'],
            'state' => $company['estado'],
            'phone' => $company['telefono'],
            'email' => $company['correo'],
            'website' => $company['website'],
            'tax_condition' => $company['tax_condition'],
        ];
    }

    private function branchSnapshot(mixed $branch): ?array
    {
        if (! $branch) {
            return null;
        }

        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'code' => $branch->code,
            'fiscal_address' => $branch->fiscal_address,
            'city' => $branch->fiscal_city,
            'state' => $branch->fiscal_state,
            'phone' => $branch->fiscal_phone,
            'email' => $branch->fiscal_email,
            'tax_condition' => $branch->tax_condition,
        ];
    }

    private function customerSnapshot(?Customer $customer): array
    {
        if (! $customer) {
            return [
                'id' => null,
                'name' => 'Consumidor final',
                'fiscal_name' => 'Consumidor final',
                'document_type' => null,
                'document_number' => null,
                'fiscal_address' => null,
                'phone' => null,
                'email' => null,
                'is_generic' => true,
            ];
        }

        return $customer->only([
            'id',
            'name',
            'fiscal_name',
            'document_type',
            'document_number',
            'fiscal_address',
            'phone',
            'email',
            'is_generic',
        ]);
    }

    private function totalsSnapshot(Sale $sale): array
    {
        return [
            'total_base_amount' => (float) $sale->total_base_amount,
            'total_local_amount' => (float) $sale->total_local_amount,
            'fiscal_taxable_base_amount' => (float) $sale->fiscal_taxable_base_amount,
            'fiscal_taxable_local_amount' => (float) $sale->fiscal_taxable_local_amount,
            'fiscal_exempt_base_amount' => (float) $sale->fiscal_exempt_base_amount,
            'fiscal_exempt_local_amount' => (float) $sale->fiscal_exempt_local_amount,
            'fiscal_exonerated_base_amount' => (float) $sale->fiscal_exonerated_base_amount,
            'fiscal_exonerated_local_amount' => (float) $sale->fiscal_exonerated_local_amount,
            'fiscal_non_taxable_base_amount' => (float) $sale->fiscal_non_taxable_base_amount,
            'fiscal_non_taxable_local_amount' => (float) $sale->fiscal_non_taxable_local_amount,
            'fiscal_tax_base_amount' => (float) $sale->fiscal_tax_base_amount,
            'fiscal_tax_local_amount' => (float) $sale->fiscal_tax_local_amount,
            'fiscal_snapshot_at' => $sale->fiscal_snapshot_at?->toISOString(),
        ];
    }

    private function itemSnapshot(SaleItem $item): array
    {
        return [
            'sale_item_id' => $item->id,
            'quantity' => $item->quantity,
            'sale_currency' => $item->sale_currency,
            'unit_price' => $item->unit_price,
            'total_amount' => $item->total_amount,
            'base_unit_price' => $item->base_unit_price,
            'base_total_amount' => $item->base_total_amount,
            'local_total_amount' => $item->local_total_amount,
            'product_snapshot' => [
                'id' => $item->product?->id,
                'name' => $item->product?->name,
                'sku' => $item->product?->sku,
                'barcode' => $item->product?->barcode,
                'variant_id' => $item->variant?->id,
                'variant_sku' => $item->variant?->sku_variant,
                'variant_color' => $item->variant?->color,
            ],
            'warehouse_snapshot' => $item->warehouse ? [
                'id' => $item->warehouse->id,
                'name' => $item->warehouse->name,
                'code' => $item->warehouse->code,
            ] : null,
            'commercial_snapshot' => [
                'price_list_id' => $item->price_list_id,
                'price_list_name' => $item->price_list_name,
                'discount_type' => $item->discount_type,
                'discount_value' => $item->discount_value,
                'discount_amount' => $item->discount_amount,
                'discount_base_amount' => $item->discount_base_amount,
                'discount_local_amount' => $item->discount_local_amount,
                'discount_reason' => $item->discount_reason,
            ],
            'fiscal_snapshot' => [
                'tax_code' => $item->fiscal_tax_code,
                'tax_source' => $item->fiscal_tax_source,
                'tax_override_code' => $item->fiscal_tax_override_code,
                'tax_name' => $item->fiscal_tax_name,
                'category' => $item->fiscal_tax_category,
                'tax_rate' => $item->fiscal_tax_rate === null ? null : (float) $item->fiscal_tax_rate,
                'prices_include_tax' => (bool) $item->fiscal_prices_include_tax,
                'taxable_base_amount' => (float) $item->fiscal_taxable_base_amount,
                'taxable_local_amount' => (float) $item->fiscal_taxable_local_amount,
                'exempt_base_amount' => (float) $item->fiscal_exempt_base_amount,
                'exempt_local_amount' => (float) $item->fiscal_exempt_local_amount,
                'exonerated_base_amount' => (float) $item->fiscal_exonerated_base_amount,
                'exonerated_local_amount' => (float) $item->fiscal_exonerated_local_amount,
                'non_taxable_base_amount' => (float) $item->fiscal_non_taxable_base_amount,
                'non_taxable_local_amount' => (float) $item->fiscal_non_taxable_local_amount,
                'tax_base_amount' => (float) $item->fiscal_tax_base_amount,
                'tax_local_amount' => (float) $item->fiscal_tax_local_amount,
                'total_base_amount' => (float) $item->fiscal_total_base_amount,
                'total_local_amount' => (float) $item->fiscal_total_local_amount,
                'snapshot_at' => $item->fiscal_snapshot_at?->toISOString(),
            ],
        ];
    }
}
