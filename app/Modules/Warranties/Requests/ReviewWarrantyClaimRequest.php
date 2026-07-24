<?php

namespace App\Modules\Warranties\Requests;

use App\Modules\Warranties\Models\WarrantyClaim;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewWarrantyClaimRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                WarrantyClaim::STATUS_UNDER_REVIEW,
                WarrantyClaim::STATUS_APPROVED,
                WarrantyClaim::STATUS_REJECTED,
            ])],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            'resolution_type' => [
                'nullable',
                'required_if:status,'.WarrantyClaim::STATUS_APPROVED,
                'required_if:status,'.WarrantyClaim::STATUS_REJECTED,
                Rule::in([
                    WarrantyClaim::RESOLUTION_REPAIR,
                    WarrantyClaim::RESOLUTION_REPLACEMENT,
                    WarrantyClaim::RESOLUTION_REFUND,
                    WarrantyClaim::RESOLUTION_REJECTED,
                    WarrantyClaim::RESOLUTION_PENDING_REVIEW,
                ])],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
