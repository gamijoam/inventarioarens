<?php

namespace App\Modules\Promotions\Models;

use App\Modules\Fiscal\Models\FiscalTaxRate;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'name',
    'code',
    'benefit_type',
    'price_currency',
    'payment_currency',
    'price_usd',
    'discount_percent',
    'discount_amount_usd',
    'priority',
    'is_active',
    'starts_at',
    'ends_at',
    'scope',
    'allows_combos',
    'fiscal_tax_mode',
    'fiscal_tax_rate_id',
])]
class Promotion extends Model
{
    use BelongsToTenant;

    public const BENEFIT_PERCENT_DISCOUNT = 'percent_discount';

    public const BENEFIT_FIXED_DISCOUNT = 'fixed_discount';

    public const BENEFIT_FIXED_ITEM_PRICE = 'fixed_item_price';

    public const BENEFIT_FIXED_BUNDLE_PRICE = 'fixed_bundle_price';

    public const BENEFIT_FREE_ITEM = 'free_item';

    public const BENEFIT_BUY_X_GET_Y = 'buy_x_get_y';

    public const PRICE_CURRENCY_USD = 'USD';

    public const PAYMENT_CURRENCY_ANY = 'ANY';

    public const PAYMENT_CURRENCY_VES = 'VES';

    public const SCOPE_INVOICE = 'invoice';

    public const SCOPE_COMBO = 'combo';

    public const SCOPE_PRODUCT_OFFER = 'product_offer';

    public const SCOPE_LEGACY_PRODUCT_DISCOUNT = 'legacy_product_discount';

    public const FISCAL_TAX_MODE_INHERIT = 'inherit_product_tax';

    public const FISCAL_TAX_MODE_OVERRIDE = 'override';

    /**
     * Descuentos que se aplican sobre el total del ticket. Los items son
     * opcionales para conservar la posibilidad de restringir promociones
     * antiguas a productos elegibles.
     *
     * @var list<string>
     */
    public const INVOICE_DISCOUNT_TYPES = [
        self::BENEFIT_PERCENT_DISCOUNT,
        self::BENEFIT_FIXED_DISCOUNT,
    ];

    public static function isInvoiceDiscountType(?string $benefitType): bool
    {
        return in_array($benefitType, self::INVOICE_DISCOUNT_TYPES, true);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PromotionItem::class);
    }

    public function fiscalTaxRate(): BelongsTo
    {
        return $this->belongsTo(FiscalTaxRate::class);
    }

    protected function casts(): array
    {
        return [
            'price_usd' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'discount_amount_usd' => 'decimal:4',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'allows_combos' => 'boolean',
            'fiscal_tax_rate_id' => 'integer',
        ];
    }

    public static function inferScope(string $benefitType, bool $hasItems): string
    {
        if (in_array($benefitType, [self::BENEFIT_FIXED_BUNDLE_PRICE, self::BENEFIT_BUY_X_GET_Y], true)) {
            return self::SCOPE_COMBO;
        }

        if (self::isInvoiceDiscountType($benefitType)) {
            return $hasItems ? self::SCOPE_LEGACY_PRODUCT_DISCOUNT : self::SCOPE_INVOICE;
        }

        return self::SCOPE_PRODUCT_OFFER;
    }

    public function scopeOfType(Builder $query, string $scope): Builder
    {
        return $query->where('scope', $scope);
    }

    public function scopeActiveAt(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $at);
            })
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $at);
            });
    }
}
