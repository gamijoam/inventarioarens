<?php

namespace App\Modules\Sync\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadSyncImageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'string', 'max:64'],
            'product_sku' => ['required', 'string', 'max:255'],
            'variant' => ['nullable', 'string', Rule::in(['original', 'medium', 'thumb'])],
            'sha256' => ['required', 'string', 'size:64'],
            'image' => ['required', 'file', 'max:10240'],
        ];
    }
}
