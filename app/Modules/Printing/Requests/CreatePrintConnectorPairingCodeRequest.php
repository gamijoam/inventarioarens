<?php

namespace App\Modules\Printing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePrintConnectorPairingCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('printing.manage') === true;
    }

    public function rules(): array
    {
        return [];
    }
}
