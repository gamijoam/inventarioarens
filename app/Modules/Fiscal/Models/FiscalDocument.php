<?php

namespace App\Modules\Fiscal\Models;

use App\Models\User;
use App\Modules\Sales\Models\Sale;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sale_id',
    'document_type',
    'document_mode',
    'status',
    'company_snapshot',
    'branch_snapshot',
    'customer_snapshot',
    'totals_snapshot',
    'snapshot_at',
    'created_by',
])]
class FiscalDocument extends Model
{
    use BelongsToTenant;

    public const DOCUMENT_TYPE_INTERNAL_PREVIEW = 'internal_preview';

    public const DOCUMENT_MODE_INTERNAL_PREVIEW = 'internal_preview';

    public const STATUS_PREVIEW = 'preview';

    protected function casts(): array
    {
        return [
            'company_snapshot' => 'array',
            'branch_snapshot' => 'array',
            'customer_snapshot' => 'array',
            'totals_snapshot' => 'array',
            'snapshot_at' => 'datetime',
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
        return $this->hasMany(FiscalDocumentItem::class);
    }
}
