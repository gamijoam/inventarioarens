<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Customers\Models\Customer;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Promotions\Models\SalePromotionApplication;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Support\Sync\Syncable;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'status',
    'customer_id',
    'total_base_amount',
    'total_local_amount',
    'created_by',
    'confirmed_at',
    'cancelled_at',
])]
class Sale extends Model
{
    use BelongsToTenant, Syncable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_VOIDED = 'voided';

    protected function syncOutboxMethod(string $action): ?string
    {
        if ($action !== 'updated') {
            return null;
        }

        // Solo las ventas confirmadas del módulo Sales (sin POS) emiten
        // `sale.confirmed`. Las del POS viajan con pos.order.* (sale embebido),
        // por lo que no deben duplicar evento.
        if ($this->status !== self::STATUS_CONFIRMED) {
            return null;
        }

        if ($this->relationLoaded('posOrder') ? $this->posOrder !== null : $this->posOrder()->exists()) {
            return null;
        }

        return 'saleConfirmed';
    }

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_base_amount' => 'decimal:4',
            'total_local_amount' => 'decimal:4',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function posOrder(): HasOne
    {
        return $this->hasOne(PosOrder::class);
    }

    public function receivable(): HasOne
    {
        return $this->hasOne(AccountsReceivable::class);
    }

    public function salesReturns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    public function promotionApplications(): HasMany
    {
        return $this->hasMany(SalePromotionApplication::class);
    }
}
