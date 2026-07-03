<?php

namespace App\Modules\AccessControl\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string', 'max:150'],
        ];
    }
}
