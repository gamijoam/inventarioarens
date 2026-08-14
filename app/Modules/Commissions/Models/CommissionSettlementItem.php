<?php

namespace App\Modules\Commissions\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['commission_settlement_id', 'commission_entry_id', 'commission_base_amount'])]
class CommissionSettlementItem extends Model
{
    use BelongsToTenant;

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CommissionSettlement::class, 'commission_settlement_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(CommissionEntry::class, 'commission_entry_id');
    }
}
