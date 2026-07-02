<?php

namespace App\Modules\Kardex\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KardexProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('kardex.view') ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'warehouse_id' => [
                'sometimes',
                'integer',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
        ];
    }
}
