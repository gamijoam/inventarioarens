<?php

namespace App\Modules\Printing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'paper_width_mm' => $this->paper_width_mm,
            'characters_per_line' => $this->characters_per_line,
            'header_text' => $this->header_text,
            'footer_text' => $this->footer_text,
            'warranty_policy_text' => $this->warranty_policy_text,
            'legal_text' => $this->legal_text,
            'logo_text' => $this->logo_text,
            'show_tenant_slug' => (bool) $this->show_tenant_slug,
            'show_sale_number' => (bool) $this->show_sale_number,
            'show_paid_at' => (bool) $this->show_paid_at,
            'show_cashier' => (bool) $this->show_cashier,
            'show_cash_register' => (bool) $this->show_cash_register,
            'show_branch' => (bool) $this->show_branch,
            'show_customer' => (bool) $this->show_customer,
            'show_item_sku' => (bool) $this->show_item_sku,
            'show_item_discount' => (bool) $this->show_item_discount,
            'show_item_serials' => (bool) $this->show_item_serials,
            'show_warranty_summary' => (bool) $this->show_warranty_summary,
            'show_total_local' => (bool) $this->show_total_local,
            'show_payment_rate' => (bool) $this->show_payment_rate,
            'show_payment_reference' => (bool) $this->show_payment_reference,
            'show_receivable_balance' => (bool) $this->show_receivable_balance,
            'show_non_fiscal_text' => (bool) $this->show_non_fiscal_text,
            'cut_paper' => (bool) $this->cut_paper,
            'open_cash_drawer' => (bool) $this->open_cash_drawer,
            'copies' => (int) $this->copies,
            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
