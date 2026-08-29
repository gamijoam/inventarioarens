<?php

namespace App\Modules\Fiscal\Requests;

use App\Modules\Fiscal\Models\FiscalTaxRate;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFiscalTaxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->input('code')) ? mb_strtoupper(trim($this->input('code'))) : $this->input('code'),
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
        ]);
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('fiscal_tax_rates', 'code')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'name' => ['required', 'string', 'max:120'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'category' => ['required', 'string', Rule::in(FiscalTaxRate::CATEGORIES)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->requiresZeroRate() && (float) $this->input('rate') !== 0.0) {
                $validator->errors()->add('rate', 'Las categorias no gravadas deben tener tasa 0.');
            }
        });
    }

    private function requiresZeroRate(): bool
    {
        return in_array($this->input('category'), [
            FiscalTaxRate::CATEGORY_EXEMPT,
            FiscalTaxRate::CATEGORY_EXONERATED,
            FiscalTaxRate::CATEGORY_NON_TAXABLE,
        ], true);
    }
}
