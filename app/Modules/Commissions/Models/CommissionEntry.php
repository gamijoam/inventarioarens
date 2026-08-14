<?php

namespace App\Modules\Commissions\Models;

use App\Models\User;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'entry_uuid',
    'commission_plan_id',
    'sale_id',
    'pos_order_id',
    'sale_item_id',
    'accounts_receivable_payment_id',
    'sales_return_id',
    'beneficiary_user_id',
    'beneficiary_role',
    'entry_type',
    'original_entry_id',
    'plan_name_snapshot',
    'percentage_snapshot',
    'sale_currency',
    'source_amount',
    'eligible_base_amount',
    'exchange_rate_type_id',
    'exchange_rate_type_code',
    'exchange_rate',
    'commission_base_amount',
    'status',
    'adjustment_reason',
    'created_by',
    'approved_by',
    'approved_at',
    'earned_at',
    'available_at',
])]
class CommissionEntry extends Model
{
    use BelongsToTenant;

    public const TYPE_EARNING = 'earning';

    public const TYPE_REVERSAL = 'reversal';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const STATUS_PENDING = 'pending';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    protected function casts(): array
    {
        return [
            'percentage_snapshot' => 'decimal:4',
            'source_amount' => 'decimal:4',
            'eligible_base_amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'commission_base_amount' => 'decimal:4',
            'earned_at' => 'datetime',
            'available_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CommissionPlan::class, 'commission_plan_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function posOrder(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function originalEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_entry_id');
    }
}
