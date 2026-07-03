<?php

namespace App\Modules\AccessControl\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['required', 'string', 'max:150'],
        ];
    }
}
