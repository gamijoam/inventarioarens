<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'warehouse_id',
    'code',
    'name',
    'status',
    'count_type',
    'scheduled_at',
    'started_at',
    'completed_at',
    'created_by',
    'approved_by',
    'notes',
])]
class StockCount extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CAPTURING = 'capturing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_FULL = 'full';

    public const TYPE_CATEGORY = 'category';

    public const TYPE_SPOT = 'spot';

    public const ALLOWED_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_CAPTURING,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const ALLOWED_TYPES = [
        self::TYPE_FULL,
        self::TYPE_CATEGORY,
        self::TYPE_SPOT,
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
