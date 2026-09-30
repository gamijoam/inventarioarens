<?php

namespace App\Modules\AccessControl\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantUserRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantUser = $this->route('tenantUser') ?? $this->route('user');
        $userId = $tenantUser instanceof User ? $tenantUser->id : (int) $tenantUser;

        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
        ];
    }
}
