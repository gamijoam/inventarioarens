<?php

namespace App\Modules\Sync\Models;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncTenantReadiness extends Model
{
    use BelongsToTenant;

    protected $table = 'sync_tenant_readiness';

    protected $fillable = [
        'tenant_id',
        'installation_code',
        'node_code',
        'node_name',
        'status',
        'last_push_at',
        'last_pull_at',
        'last_apply_at',
        'last_success_at',
        'initial_sync_completed_at',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'last_push_at' => 'datetime',
        'last_pull_at' => 'datetime',
        'last_apply_at' => 'datetime',
        'last_success_at' => 'datetime',
        'initial_sync_completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
