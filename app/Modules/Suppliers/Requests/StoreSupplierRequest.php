<?php

namespace App\Modules\Suppliers\Requests;

use App\Modules\Suppliers\Models\Supplier;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'document_type' => ['nullable', 'string', Rule::in([
                Supplier::DOCUMENT_V,
                Supplier::DOCUMENT_E,
                Supplier::DOCUMENT_J,
                Supplier::DOCUMENT_G,
                Supplier::DOCUMENT_P,
            ])],
            'document_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('suppliers', 'document_number')
                    ->where('tenant_id', $tenantId)
                    ->where('document_type', $this->input('document_type')),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'fiscal_address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
