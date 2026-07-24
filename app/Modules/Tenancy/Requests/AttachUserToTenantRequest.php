<?php

namespace App\Modules\Tenancy\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachUserToTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:user_id'],
            'name' => ['nullable', 'string', 'max:150'],
            'password' => ['nullable', 'string', 'min:8'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['required', 'string', 'max:150'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ];
    }
}
