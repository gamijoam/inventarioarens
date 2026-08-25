<?php

namespace App\Modules\Tenancy\Requests;

use App\Support\Capabilities\BaseCapabilities;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantCapabilitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'capabilities' => ['required', 'array'],
            'capabilities.*' => ['string', Rule::in(BaseCapabilities::ALL)],
        ];
    }
}
