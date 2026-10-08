<?php

namespace App\Modules\Currency\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExchangeRateTypeRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];
        $type = $this->route('type');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('exchange_rate_types', 'code')
                    ->where(fn ($query) => $query->whereIn('tenant_id', $tenantIds))
                    ->ignore($type?->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }


    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $type = $this->route('type');
            $isDefault = $this->input('is_default', $type?->is_default ?? false);
            $isActive = $this->input('is_active', $type?->is_active ?? true);

            if (filter_var($isDefault, FILTER_VALIDATE_BOOLEAN) && ! filter_var($isActive, FILTER_VALIDATE_BOOLEAN)) {
                $validator->errors()->add('is_default', 'El tipo predeterminado debe estar activo.');
            }

            if ($type && ! filter_var($isActive, FILTER_VALIDATE_BOOLEAN) && $type->rates()->where('is_active', true)->exists()) {
                $validator->errors()->add('is_active', 'Desactiva primero la tasa vigente de este tipo.');
            }
        });
    }

    public function authorize(): bool
    {
        return true;
    }
}
