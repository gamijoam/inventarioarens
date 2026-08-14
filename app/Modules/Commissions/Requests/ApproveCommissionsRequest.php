<?php

namespace App\Modules\Commissions\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveCommissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('commissions.approve') === true;
    }

    public function rules(): array
    {
        return [
            'entry_ids' => ['required', 'array', 'min:1', 'max:500'],
            'entry_ids.*' => ['integer', 'distinct'],
        ];
    }
}
