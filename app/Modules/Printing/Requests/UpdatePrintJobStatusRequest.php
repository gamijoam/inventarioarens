<?php

namespace App\Modules\Printing\Requests;

use App\Modules\Printing\Models\PrintJob;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePrintJobStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('printing.print') === true
            || $this->user()?->can('printing.digital') === true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                PrintJob::STATUS_SENT,
                PrintJob::STATUS_PRINTED,
                PrintJob::STATUS_GENERATED,
                PrintJob::STATUS_FAILED,
            ])],
            'message' => ['nullable', 'string', 'max:1000'],
            'digital_pdf_path' => ['nullable', 'string', 'max:500'],
            'digital_html_path' => ['nullable', 'string', 'max:500'],
        ];
    }
}
