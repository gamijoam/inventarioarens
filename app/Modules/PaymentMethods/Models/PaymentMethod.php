<?php

namespace App\Modules\PaymentMethods\Models;

use App\Modules\Products\Concerns\PropagatesCatalogToSpinoffs;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
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
    use BelongsToTenant, PropagatesCatalogToSpinoffs;

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

    protected static function propagateToSpinoffs(Model $model): void
    {
        $spinoffs = Tenant::query()
            ->where('parent_id', $model->tenant_id)
            ->where('is_group', false)
            ->get();

        $svc = app(SharedCatalogPropagationService::class);
        foreach ($spinoffs as $spinoff) {
            $svc->ensurePaymentMethodCopyFor($model, $spinoff);
        }
    }

    public function priceLists(): BelongsToMany
    {
        return $this->belongsToMany(PriceList::class, 'price_list_payment_method', 'payment_method_id', 'price_list_id')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    public function allowsCurrency(string $currency): bool
    {
        return $this->currency_mode === self::CURRENCY_FLEXIBLE
            || strtoupper($this->currency_mode) === strtoupper($currency);
    }
}
