<?php

namespace App\Modules\ProductEntries\Models;

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
class ProductEntry extends Model
{
    use BelongsToTenant;

    public const STATUS_PROCESSED = 'processed';

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductEntryItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
