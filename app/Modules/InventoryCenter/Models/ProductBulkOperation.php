<?php

namespace App\Modules\InventoryCenter\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'user_id',
    'action',
    'filters',
    'payload',
    'status',
    'requested_count',
    'processed_count',
    'updated_count',
    'skipped_count',
    'error',
    'started_at',
    'completed_at',
])]
class ProductBulkOperation extends Model
{
    use BelongsToTenant;

    public const ACTION_ASSIGN_FISCAL_TAX_RATE = 'assign_fiscal_tax_rate';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'payload' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
