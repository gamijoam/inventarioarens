<?php

namespace App\Modules\Warehouses\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWarehouseLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer'],
            'name' => ['sometimes', 'string', 'max:100'],
            'code' => ['sometimes', 'string', 'max:50'],
            'aisle' => ['sometimes', 'nullable', 'string', 'max:20'],
            'rack' => ['sometimes', 'nullable', 'string', 'max:20'],
            'bin' => ['sometimes', 'nullable', 'string', 'max:20'],
            'level' => ['sometimes', 'nullable', 'string', 'max:20'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
