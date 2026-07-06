<?php

namespace App\Modules\Sync\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PullSyncEventsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'node_code' => ['required', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
