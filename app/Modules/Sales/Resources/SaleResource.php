<?php

namespace App\Modules\Sales\Resources;

use App\Modules\AccountsReceivable\Resources\AccountsReceivableResource;
use App\Modules\Customers\Resources\CustomerResource;
use App\Modules\POS\Resources\PosPaymentResource;
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
            'created_by' => $this->created_by,
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'items_count' => $this->items_count ?? $this->whenLoaded('items', fn () => $this->items->count()),
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
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
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
