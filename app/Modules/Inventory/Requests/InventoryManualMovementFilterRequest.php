<?php

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryManualMovementFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manual_movements.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'created_by' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }
}
