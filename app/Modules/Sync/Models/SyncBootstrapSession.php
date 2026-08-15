<?php

namespace App\Modules\Sync\Models;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncBootstrapSession extends Model
{
    use BelongsToTenant;

    protected $table = 'sync_bootstrap_sessions';

    protected $fillable = [
        'tenant_id',
        'target_node_id',
        'installation_code',
        'snapshot_key',
        'session_token_hash',
        'snapshot_cutoff_id',
        'snapshot_event_count',
        'status',
        'expires_at',
        'completed_at',
        'last_error',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
