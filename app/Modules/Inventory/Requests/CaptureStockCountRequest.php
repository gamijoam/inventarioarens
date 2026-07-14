<?php

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CaptureStockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'captures' => ['required', 'array', 'min:1'],
            'captures.*' => ['required', 'array'],
            'captures.*.item_id' => ['required', 'integer'],
            'captures.*.counted_quantity' => ['required', 'numeric', 'gte:0'],
            'captures.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
