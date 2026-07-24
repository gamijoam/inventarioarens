<?php

namespace App\Modules\Tenancy\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $admin = $this->route('admin');
        $adminId = $admin instanceof User ? $admin->id : (int) $admin;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($adminId),
            ],
            'is_platform_admin' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
