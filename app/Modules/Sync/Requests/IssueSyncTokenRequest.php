<?php

namespace App\Modules\Sync\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssueSyncTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sync.issue_token');
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
