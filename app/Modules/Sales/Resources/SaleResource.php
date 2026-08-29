<?php

namespace App\Modules\Sales\Resources;

use App\Modules\AccountsReceivable\Resources\AccountsReceivableResource;
use App\Modules\Customers\Resources\CustomerResource;
use App\Modules\POS\Resources\PosPaymentResource;
use App\Modules\SalesReturns\Resources\SalesReturnResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'status' => $this->status,
            'customer_id' => $this->customer_id,
            'total_base_amount' => (float) $this->total_base_amount,
            'total_local_amount' => (float) $this->total_local_amount,
            'fiscal_taxable_base_amount' => (float) $this->fiscal_taxable_base_amount,
            'fiscal_taxable_local_amount' => (float) $this->fiscal_taxable_local_amount,
            'fiscal_exempt_base_amount' => (float) $this->fiscal_exempt_base_amount,
            'fiscal_exempt_local_amount' => (float) $this->fiscal_exempt_local_amount,
            'fiscal_exonerated_base_amount' => (float) $this->fiscal_exonerated_base_amount,
            'fiscal_exonerated_local_amount' => (float) $this->fiscal_exonerated_local_amount,
            'fiscal_non_taxable_base_amount' => (float) $this->fiscal_non_taxable_base_amount,
            'fiscal_non_taxable_local_amount' => (float) $this->fiscal_non_taxable_local_amount,
            'fiscal_tax_base_amount' => (float) $this->fiscal_tax_base_amount,
            'fiscal_tax_local_amount' => (float) $this->fiscal_tax_local_amount,
            'fiscal_snapshot_at' => $this->fiscal_snapshot_at?->toISOString(),
            'created_by' => $this->created_by,
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'items_count' => $this->items_count ?? $this->whenLoaded('items', fn () => $this->items->count()),
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'promotion_applications' => $this->whenLoaded('promotionApplications', fn () => $this->promotionApplications->map(fn ($application) => [
                'id' => $application->id,
                'slot' => $application->slot,
                'scope' => $application->scope,
                'status' => $application->status,
                'instance_uuid' => $application->instance_uuid,
                'promotion_id' => $application->promotion_id,
                'promotion_code' => $application->promotion_code,
                'promotion_name' => $application->promotion_name,
                'benefit_type' => $application->benefit_type,
                'payment_currency' => $application->payment_currency,
                'price_usd' => $application->price_usd === null ? null : (float) $application->price_usd,
                'discount_percent' => $application->discount_percent === null ? null : (float) $application->discount_percent,
                'discount_amount_usd' => $application->discount_amount_usd === null ? null : (float) $application->discount_amount_usd,
                'conditions_snapshot' => $application->conditions_snapshot,
                'base_before_amount' => (float) $application->base_before_amount,
                'local_before_amount' => (float) $application->local_before_amount,
                'base_adjustment_amount' => (float) $application->base_adjustment_amount,
                'local_adjustment_amount' => (float) $application->local_adjustment_amount,
                'base_after_amount' => (float) $application->base_after_amount,
                'local_after_amount' => (float) $application->local_after_amount,
                'requested_at' => $application->requested_at?->toISOString(),
                'validated_at' => $application->validated_at?->toISOString(),
                'rejected_at' => $application->rejected_at?->toISOString(),
                'items' => $application->relationLoaded('items') ? $application->items->map(fn ($item) => [
                    'id' => $item->id,
                    'sale_item_id' => $item->sale_item_id,
                    'quantity' => (float) $item->quantity,
                    'base_before_amount' => (float) $item->base_before_amount,
                    'local_before_amount' => (float) $item->local_before_amount,
                    'base_adjustment_amount' => (float) $item->base_adjustment_amount,
                    'local_adjustment_amount' => (float) $item->local_adjustment_amount,
                    'base_after_amount' => (float) $item->base_after_amount,
                    'local_after_amount' => (float) $item->local_after_amount,
                    'created_at' => $item->created_at?->toISOString(),
                    'updated_at' => $item->updated_at?->toISOString(),
                ])->values() : [],
                'created_at' => $application->created_at?->toISOString(),
                'updated_at' => $application->updated_at?->toISOString(),
            ])->values()),
            'pos_order' => $this->whenLoaded('posOrder', fn () => $this->posOrder ? [
                'id' => $this->posOrder->id,
                'status' => $this->posOrder->status,
                'cashier_id' => $this->posOrder->cashier_id,
                'cashier_name' => $this->posOrder->cashier?->name,
                'cash_register_session_id' => $this->posOrder->cash_register_session_id,
                'paid_at' => $this->posOrder->paid_at?->toISOString(),
                'total_base_amount' => (float) $this->posOrder->total_base_amount,
                'total_local_amount' => (float) $this->posOrder->total_local_amount,
                'paid_base_amount' => (float) $this->posOrder->paid_base_amount,
                'paid_local_amount' => (float) $this->posOrder->paid_local_amount,
                'cash_register_session' => $this->posOrder->relationLoaded('cashRegisterSession') && $this->posOrder->cashRegisterSession ? [
                    'id' => $this->posOrder->cashRegisterSession->id,
                    'status' => $this->posOrder->cashRegisterSession->status,
                    'branch_id' => $this->posOrder->cashRegisterSession->branch_id,
                    'branch_name' => $this->posOrder->cashRegisterSession->branch?->name,
                    'cash_register_id' => $this->posOrder->cashRegisterSession->cash_register_id,
                    'cash_register_name' => $this->posOrder->cashRegisterSession->cashRegister?->name,
                    'opened_at' => $this->posOrder->cashRegisterSession->opened_at?->toISOString(),
                    'closed_at' => $this->posOrder->cashRegisterSession->closed_at?->toISOString(),
                ] : null,
                'payments' => $this->posOrder->relationLoaded('payments')
                    ? PosPaymentResource::collection($this->posOrder->payments)
                    : [],
            ] : null),
            'receivable' => AccountsReceivableResource::make($this->whenLoaded('receivable')),
            'sales_returns' => SalesReturnResource::collection($this->whenLoaded('salesReturns')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
