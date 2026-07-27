<?php

namespace App\Modules\Sync\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateSyncPairingCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sync.issue_token');
    }

    public function rules(): array
    {
        return [
            'target_tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'user_email' => ['required', 'email', 'max:255'],
            'node_name' => ['required', 'string', 'max:120'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:5', 'max:60'],
        ];
    }
}
