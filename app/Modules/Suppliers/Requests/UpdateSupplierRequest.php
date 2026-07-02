<?php

namespace App\Modules\Suppliers\Requests;

use App\Modules\Suppliers\Models\Supplier;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        $supplier = $this->route('supplier');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'document_type' => ['sometimes', 'nullable', 'string', Rule::in([
                Supplier::DOCUMENT_V,
                Supplier::DOCUMENT_E,
                Supplier::DOCUMENT_J,
                Supplier::DOCUMENT_G,
                Supplier::DOCUMENT_P,
            ])],
            'document_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('suppliers', 'document_number')
                    ->where('tenant_id', $tenantId)
                    ->where('document_type', $this->input('document_type', $supplier->document_type))
                    ->ignore($supplier->id),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'fiscal_address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
