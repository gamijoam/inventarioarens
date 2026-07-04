<?php

namespace App\Modules\PaymentMethods\Models;

use App\Modules\Products\Models\PriceList;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'name',
    'code',
    'method',
    'currency_mode',
    'requires_reference',
    'is_active',
    'sort_order',
])]
class PaymentMethod extends Model
{
    use BelongsToTenant;

    public const CURRENCY_USD = 'USD';
    public const CURRENCY_VES = 'VES';
    public const CURRENCY_FLEXIBLE = 'flexible';

    protected function casts(): array
    {
        return [
            'requires_reference' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function priceLists(): BelongsToMany
    {
        return $this->belongsToMany(PriceList::class, 'price_list_payment_method')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    public function allowsCurrency(string $currency): bool
    {
        return $this->currency_mode === self::CURRENCY_FLEXIBLE
            || strtoupper($this->currency_mode) === strtoupper($currency);
    }
}
