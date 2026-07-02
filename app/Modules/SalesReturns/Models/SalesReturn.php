<?php

namespace App\Modules\SalesReturns\Models;

use App\Models\User;
use App\Modules\Sales\Models\Sale;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sale_id',
    'status',
    'reason',
    'created_by',
    'processed_at',
])]
class SalesReturn extends Model
{
    use BelongsToTenant;

    public const STATUS_PROCESSED = 'processed';

    protected $table = 'sales_returns';

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }
}
