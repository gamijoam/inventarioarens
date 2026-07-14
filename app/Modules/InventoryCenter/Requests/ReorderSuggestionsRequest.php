<?php

namespace App\Modules\InventoryCenter\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderSuggestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
