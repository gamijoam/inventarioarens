<?php

namespace App\Modules\Sync\Models;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncNode extends Model
{
    use BelongsToTenant;

    protected $table = 'sync_nodes';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'type',
        'status',
        'branch_id',
        'last_seen_at',
        'metadata',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
