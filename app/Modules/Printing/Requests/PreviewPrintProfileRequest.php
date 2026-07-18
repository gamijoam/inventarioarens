<?php

namespace App\Modules\Printing\Requests;

use App\Modules\Printing\Models\PrintProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewPrintProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('printing.manage') === true
            || $this->user()?->can('printing.digital') === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'paper_width_mm' => ['sometimes', 'required', 'integer', Rule::in([PrintProfile::WIDTH_58, PrintProfile::WIDTH_80])],
            'characters_per_line' => ['sometimes', 'required', 'integer', 'min:24', 'max:64'],
            'header_text' => ['nullable', 'string', 'max:1000'],
            'footer_text' => ['nullable', 'string', 'max:1000'],
            'warranty_policy_text' => ['nullable', 'string', 'max:1000'],
            'legal_text' => ['nullable', 'string', 'max:160'],
            'logo_text' => ['nullable', 'string', 'max:120'],
            'show_tenant_slug' => ['sometimes', 'boolean'],
            'show_sale_number' => ['sometimes', 'boolean'],
            'show_paid_at' => ['sometimes', 'boolean'],
            'show_cashier' => ['sometimes', 'boolean'],
            'show_cash_register' => ['sometimes', 'boolean'],
            'show_branch' => ['sometimes', 'boolean'],
            'show_customer' => ['sometimes', 'boolean'],
            'show_item_sku' => ['sometimes', 'boolean'],
            'show_item_discount' => ['sometimes', 'boolean'],
            'show_item_serials' => ['sometimes', 'boolean'],
            'show_warranty_summary' => ['sometimes', 'boolean'],
            'show_total_local' => ['sometimes', 'boolean'],
            'show_payment_rate' => ['sometimes', 'boolean'],
            'show_payment_reference' => ['sometimes', 'boolean'],
            'show_receivable_balance' => ['sometimes', 'boolean'],
            'show_non_fiscal_text' => ['sometimes', 'boolean'],
            'cut_paper' => ['sometimes', 'boolean'],
            'open_cash_drawer' => ['sometimes', 'boolean'],
            'copies' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
