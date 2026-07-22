<?php

namespace App\Modules\DataImport\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartDataImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('data_import.create') === true;
    }

    public function rules(): array
    {
        return [
            'meta' => ['nullable', 'array'],
        ];
    }
}
