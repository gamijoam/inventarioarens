<?php

namespace App\Modules\Printing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListPrintConnectorJobsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
