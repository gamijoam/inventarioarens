<?php

namespace App\Modules\Products\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'code',
    'description',
    'is_default',
    'is_active',
    'sort_order',
])]
class PriceList extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function productPrices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function paymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(PaymentMethod::class, 'price_list_payment_method', 'price_list_id', 'payment_method_id')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }
}
