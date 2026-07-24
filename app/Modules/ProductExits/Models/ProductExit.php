<?php

namespace App\Modules\ProductExits\Models;

use App\Models\User;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sequence',
    'document_number',
    'reason',
    'reference',
    'notes',
    'status',
    'created_by',
    'processed_at',
])]
class ProductExit extends Model
{
    use BelongsToTenant;

    public const STATUS_PROCESSED = 'processed';

    public const REASON_DAMAGED = 'damaged';

    public const REASON_LOST = 'lost';

    public const REASON_INTERNAL_USE = 'internal_use';

    public const REASON_WARRANTY = 'warranty';

    public const REASON_ADMINISTRATIVE = 'administrative';

    public const REASON_OTHER = 'other';

    public const REASONS = [
        self::REASON_DAMAGED,
        self::REASON_LOST,
        self::REASON_INTERNAL_USE,
        self::REASON_WARRANTY,
        self::REASON_ADMINISTRATIVE,
        self::REASON_OTHER,
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductExitItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
