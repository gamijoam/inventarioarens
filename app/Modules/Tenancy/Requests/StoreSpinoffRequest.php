<?php

namespace App\Modules\Tenancy\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSpinoffRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'branch' => $this->normalizeOptionalObject($this->input('branch')),
            'warehouse' => $this->normalizeOptionalObject($this->input('warehouse')),
            'exchange_rate_type' => $this->normalizeOptionalObject($this->input('exchange_rate_type')),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('tenants', 'slug')],
            'domain' => ['nullable', 'string', 'max:150', Rule::unique('tenants', 'domain')],
            'plan' => ['nullable', 'string', 'max:50'],

            'admin' => ['required', 'array'],
            'admin.name' => ['required', 'string', 'max:150'],
            'admin.email' => ['required', 'email', 'max:255'],
            'admin.password' => ['nullable', 'string', 'min:8'],

            'branch' => ['nullable', 'array'],
            'branch.name' => ['required_with:branch', 'string', 'max:150'],
            'branch.code' => ['required_with:branch', 'string', 'max:50'],

            'warehouse' => ['nullable', 'array'],
            'warehouse.name' => ['required_with:warehouse', 'string', 'max:150'],
            'warehouse.code' => ['required_with:warehouse', 'string', 'max:50'],

            'exchange_rate_type' => ['nullable', 'array'],
            'exchange_rate_type.code' => ['required_with:exchange_rate_type', 'string', 'max:20'],
            'exchange_rate_type.name' => ['required_with:exchange_rate_type', 'string', 'max:150'],
        ];
    }

    private function normalizeOptionalObject(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $filtered = array_filter($value, static fn ($item) => $item !== null && $item !== '');

        return $filtered === [] ? null : $value;
    }
}
