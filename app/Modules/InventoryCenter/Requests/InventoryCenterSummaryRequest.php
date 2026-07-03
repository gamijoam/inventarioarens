<?php

namespace App\Modules\InventoryCenter\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryCenterSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user?->can('products.view') || $user?->can('inventory.view');
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'tracking_type' => ['nullable', Rule::in(['quantity', 'serialized'])],
            'stock_status' => ['nullable', Rule::in(['all', 'available', 'low', 'out'])],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
