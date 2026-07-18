<?php

namespace App\Modules\Printing\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegister;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id',
    'cash_register_id',
    'print_profile_id',
    'name',
    'code',
    'output_mode',
    'printer_type',
    'printer_name',
    'network_host',
    'network_port',
    'digital_directory',
    'save_html_copy',
    'is_active',
])]
class PrinterStation extends Model
{
    use BelongsToTenant;

    public const OUTPUT_THERMAL = 'thermal';

    public const OUTPUT_DIGITAL = 'digital';

    public const OUTPUT_BOTH = 'both';

    public const PRINTER_WINDOWS = 'windows_printer';

    public const PRINTER_NETWORK = 'network';

    protected function casts(): array
    {
        return [
            'network_port' => 'integer',
            'save_html_copy' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PrintProfile::class, 'print_profile_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }
}
