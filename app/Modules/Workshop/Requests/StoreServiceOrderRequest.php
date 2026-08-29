<?php

namespace App\Modules\Workshop\Requests;

use App\Modules\Workshop\Models\ServiceOrder;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceOrderRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'type' => ['required', 'string', Rule::in([ServiceOrder::TYPE_REPAIR, ServiceOrder::TYPE_WARRANTY])],
            'warranty_claim_id' => [
                'nullable',
                'integer',
                Rule::exists('warranty_claims', 'id')->where('tenant_id', $tenantId),
            ],
            'resolution' => [
                'required_if:type,'.ServiceOrder::TYPE_WARRANTY,
                'nullable',
                'string',
                Rule::in(ServiceOrder::RESOLUTIONS),
            ],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:60'],
            'device_description' => ['nullable', 'string', 'max:255'],
            'issue_description' => ['nullable', 'string'],
            'priority' => ['sometimes', 'string', 'max:40'],
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
