<?php

namespace App\Modules\Printing\Requests;

use App\Modules\Printing\Models\PrintProfile;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePrintProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('printing.manage') === true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('print_profiles', 'name')->where('tenant_id', $tenantId)],
            'paper_width_mm' => ['required', 'integer', Rule::in([PrintProfile::WIDTH_58, PrintProfile::WIDTH_80])],
            'characters_per_line' => ['required', 'integer', 'min:24', 'max:64'],
            'header_text' => ['nullable', 'string', 'max:1000'],
            'footer_text' => ['nullable', 'string', 'max:1000'],
            'logo_text' => ['nullable', 'string', 'max:120'],
            'show_warranty_summary' => ['sometimes', 'boolean'],
            'cut_paper' => ['sometimes', 'boolean'],
            'open_cash_drawer' => ['sometimes', 'boolean'],
            'copies' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
