<?php

namespace App\Modules\Printing\Requests;

use App\Modules\Printing\Models\PrinterStation;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePrinterStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('printing.manage') === true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'cash_register_id' => ['nullable', Rule::exists('cash_registers', 'id')->where('tenant_id', $tenantId)],
            'print_profile_id' => ['required', Rule::exists('print_profiles', 'id')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:50', Rule::unique('printer_stations', 'code')->where('tenant_id', $tenantId)],
            'output_mode' => ['required', Rule::in([PrinterStation::OUTPUT_THERMAL, PrinterStation::OUTPUT_DIGITAL, PrinterStation::OUTPUT_BOTH])],
            'printer_type' => ['required', Rule::in([PrinterStation::PRINTER_WINDOWS, PrinterStation::PRINTER_NETWORK])],
            'printer_name' => ['nullable', 'string', 'max:255'],
            'network_host' => ['nullable', 'string', 'max:255'],
            'network_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'digital_directory' => ['nullable', 'string', 'max:500'],
            'save_html_copy' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->sometimes('network_host', 'required|string|max:255', fn ($input): bool => ($input->printer_type ?? null) === PrinterStation::PRINTER_NETWORK);
        $validator->sometimes('network_port', 'required|integer|min:1|max:65535', fn ($input): bool => ($input->printer_type ?? null) === PrinterStation::PRINTER_NETWORK);
        $validator->sometimes('printer_name', 'required|string|max:255', fn ($input): bool => ($input->printer_type ?? null) === PrinterStation::PRINTER_WINDOWS && in_array($input->output_mode ?? null, [PrinterStation::OUTPUT_THERMAL, PrinterStation::OUTPUT_BOTH], true));
    }
}
