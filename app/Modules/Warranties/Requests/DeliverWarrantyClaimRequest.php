<?php

namespace App\Modules\Warranties\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliverWarrantyClaimRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
