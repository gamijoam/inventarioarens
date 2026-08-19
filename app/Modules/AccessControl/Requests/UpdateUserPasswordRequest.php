<?php

namespace App\Modules\AccessControl\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateUserPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'new_password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'confirm_password' => ['required', 'same:new_password'],
        ];
    }
}
