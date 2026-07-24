<?php

namespace App\Modules\POS\Models;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pos_order_id',
    'payment_method_id',
    'method',
    'currency',
    'amount',
    'amount_base',
    'amount_local',
    'exchange_rate_type_id',
    'exchange_rate_type_code',
    'exchange_rate',
    'status',
    'reference',
    'external_provider',
    'metadata',
])]
class PosPayment extends Model
{
    use BelongsToTenant;

    public const METHOD_CASH = 'cash';

    public const METHOD_CARD = 'card';

    public const METHOD_MOBILE_PAYMENT = 'mobile_payment';

    public const METHOD_TRANSFER = 'transfer';

    public const METHOD_ZELLE = 'zelle';

    public const METHOD_EXTERNAL_FINANCING = 'external_financing';

    public const METHOD_OTHER = 'other';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CAPTURED = 'captured';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'amount_base' => 'decimal:4',
            'amount_local' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function exchangeRateType(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateType::class);
    }
}
