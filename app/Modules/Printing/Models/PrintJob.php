<?php

namespace App\Modules\Printing\Models;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Sales\Models\Sale;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'printer_station_id',
    'print_profile_id',
    'source_type',
    'source_id',
    'pos_order_id',
    'sale_id',
    'cash_register_session_id',
    'requested_by',
    'output',
    'status',
    'is_copy',
    'attempts',
    'payload_snapshot',
    'digital_pdf_path',
    'digital_html_path',
    'last_error',
    'sent_at',
    'printed_at',
    'generated_at',
])]
class PrintJob extends Model
{
    use BelongsToTenant;

    public const OUTPUT_THERMAL = 'thermal';

    public const OUTPUT_DIGITAL = 'digital';

    public const STATUS_CREATED = 'created';

    public const STATUS_SENT = 'sent';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'is_copy' => 'boolean',
            'attempts' => 'integer',
            'payload_snapshot' => 'array',
            'sent_at' => 'datetime',
            'printed_at' => 'datetime',
            'generated_at' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(PrinterStation::class, 'printer_station_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PrintProfile::class, 'print_profile_id');
    }

    public function posOrder(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
