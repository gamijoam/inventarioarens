<?php

namespace App\Modules\Products\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
