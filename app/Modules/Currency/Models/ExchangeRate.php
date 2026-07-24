<?php

namespace App\Modules\Currency\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'exchange_rate_type_id',
    'base_currency',
    'quote_currency',
    'rate',
    'effective_at',
    'is_active',
    'source',
])]
class ExchangeRate extends Model
{
    use BelongsToTenant;

    public const BASE_USD = 'USD';

    public const QUOTE_VES = 'VES';

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'is_active' => 'boolean',
            'rate' => 'decimal:6',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateType::class, 'exchange_rate_type_id');
    }
}
