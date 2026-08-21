<?php

namespace App\Modules\Workshop\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DiagnoseServiceOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'diagnosis' => ['required', 'string'],
            'labor_base_amount' => ['sometimes', 'numeric', 'gte:0'],
            'labor_local_amount' => ['sometimes', 'numeric', 'gte:0'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
