<?php

namespace App\Modules\Sync\Models;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncOutbox extends Model
{
    use BelongsToTenant;

    protected $table = 'sync_outbox';

    protected $fillable = [
        'tenant_id',
        'event_uuid',
        'origin_node_id',
        'target_node_id',
        'target_scope',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'aggregate_uuid',
        'payload',
        'occurred_at',
        'available_at',
        'status',
        'attempts',
        'locked_at',
        'processed_at',
        'last_error',
        'idempotency_key',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'available_at' => 'datetime',
        'locked_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
