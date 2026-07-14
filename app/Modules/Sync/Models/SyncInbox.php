<?php

namespace App\Modules\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\\Support\\Tenancy\\Concerns\\BelongsToTenant;
use App\Modules\Tenancy\Models\Tenant;

class SyncInbox extends Model
{
    use BelongsToTenant;

    protected $table = 'sync_inbox';

    protected $fillable = [
        'tenant_id',
        'event_uuid',
        'origin_node_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload_hash',
        'payload',
        'status',
        'received_at',
        'applied_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
