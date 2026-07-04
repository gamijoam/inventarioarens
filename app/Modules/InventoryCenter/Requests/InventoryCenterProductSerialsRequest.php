<?php

namespace App\Modules\InventoryCenter\Requests;

use App\Modules\Inventory\Models\ProductUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryCenterProductSerialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user?->can('products.view');
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([
                'all',
                ProductUnit::STATUS_AVAILABLE,
                ProductUnit::STATUS_RESERVED,
                ProductUnit::STATUS_SOLD,
                ProductUnit::STATUS_DAMAGED,
                ProductUnit::STATUS_REMOVED,
                ProductUnit::STATUS_WARRANTY_HOLD,
            ])],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
