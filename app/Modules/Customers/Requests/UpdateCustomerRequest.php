<?php

namespace App\Modules\Customers\Requests;

use App\Modules\Customers\Models\Customer;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        $customer = $this->route('customer');
        $documentType = $this->input('document_type', $customer?->document_type);

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'document_type' => [
                'sometimes',
                'required',
                'string',
                'size:1',
                Rule::in([
                    Customer::DOCUMENT_V,
                    Customer::DOCUMENT_E,
                    Customer::DOCUMENT_J,
                    Customer::DOCUMENT_G,
                    Customer::DOCUMENT_P,
                ]),
            ],
            'document_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('customers', 'document_number')
                    ->where('tenant_id', $tenantId)
                    ->where('document_type', $documentType)
                    ->ignore($customer?->id),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'fiscal_address' => ['sometimes', 'nullable', 'string'],
            'is_generic' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
