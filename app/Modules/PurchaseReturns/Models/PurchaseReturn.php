<?php

namespace App\Modules\PurchaseReturns\Models;

use App\Models\User;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'purchase_order_id',
    'status',
    'reason',
    'created_by',
    'processed_at',
])]
class PurchaseReturn extends Model
{
    use BelongsToTenant;

    public const STATUS_PROCESSED = 'processed';

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }
}
