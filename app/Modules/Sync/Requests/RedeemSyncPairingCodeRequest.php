<?php

namespace App\Modules\Sync\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RedeemSyncPairingCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:40'],
            'node_code' => ['required', 'string', 'max:120'],
            'node_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
