<?php

namespace App\Modules\Workshop\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:60'],
            'device_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'issue_description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'string', 'max:40'],
            'resolution' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
