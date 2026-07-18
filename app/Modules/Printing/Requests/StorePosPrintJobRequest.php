<?php

namespace App\Modules\Printing\Requests;

use App\Modules\Printing\Models\PrinterStation;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePosPrintJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->boolean('copy')) {
            return $this->user()?->can('printing.reprint') === true;
        }

        $output = $this->input('output', PrinterStation::OUTPUT_THERMAL);

        if (in_array($output, [PrinterStation::OUTPUT_DIGITAL, PrinterStation::OUTPUT_BOTH], true)
            && $this->user()?->can('printing.digital') !== true) {
            return false;
        }

        return $this->user()?->can('printing.print') === true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'output' => ['sometimes', 'required', Rule::in([PrinterStation::OUTPUT_THERMAL, PrinterStation::OUTPUT_DIGITAL, PrinterStation::OUTPUT_BOTH])],
            'copy' => ['sometimes', 'boolean'],
            'printer_station_id' => [
                'nullable',
                'integer',
                Rule::exists('printer_stations', 'id')->where('tenant_id', $tenantId)->where('is_active', true),
            ],
        ];
    }
}
