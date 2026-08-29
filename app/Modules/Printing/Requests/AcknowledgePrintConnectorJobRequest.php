<?php

namespace App\Modules\Printing\Requests;

use App\Modules\Printing\Models\PrintJob;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcknowledgePrintConnectorJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'claim_token' => ['required', 'string', 'min:32', 'max:128'],
            'status' => ['required', Rule::in([
                PrintJob::STATUS_SENT,
                PrintJob::STATUS_PRINTED,
                PrintJob::STATUS_GENERATED,
                PrintJob::STATUS_FAILED,
            ])],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
