<?php

namespace App\Modules\Fiscal\Requests;

use App\Modules\Fiscal\Models\FiscalTaxRate;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFiscalTaxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        if (is_string($this->input('code'))) {
            $values['code'] = mb_strtoupper(trim($this->input('code')));
        }
        if (is_string($this->input('name'))) {
            $values['name'] = trim($this->input('name'));
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        $taxRate = $this->route('taxRate');
        $taxRateId = $taxRate instanceof FiscalTaxRate ? $taxRate->id : (int) $taxRate;

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('fiscal_tax_rates', 'code')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($taxRateId),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'rate' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'category' => ['sometimes', 'required', 'string', Rule::in(FiscalTaxRate::CATEGORIES)],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $taxRate = $this->route('taxRate');
            $category = $this->input('category', $taxRate instanceof FiscalTaxRate ? $taxRate->category : null);
            $rate = $this->input('rate', $taxRate instanceof FiscalTaxRate ? $taxRate->rate : null);

            if (in_array($category, [
                FiscalTaxRate::CATEGORY_EXEMPT,
                FiscalTaxRate::CATEGORY_EXONERATED,
                FiscalTaxRate::CATEGORY_NON_TAXABLE,
            ], true) && (float) $rate !== 0.0) {
                $validator->errors()->add('rate', 'Las categorias no gravadas deben tener tasa 0.');
            }
        });
    }
}
