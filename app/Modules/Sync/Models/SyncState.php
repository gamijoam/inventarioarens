<?php

namespace App\Modules\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\\Support\\Tenancy\\Concerns\\BelongsToTenant;
use App\Modules\Tenancy\Models\Tenant;

class SyncState extends Model
{
    use BelongsToTenant;

    protected $table = 'sync_states';

    protected $fillable = [
        'tenant_id',
        'node_id',
        'direction',
        'last_event_id',
        'last_event_uuid',
        'last_success_at',
        'last_attempt_at',
        'last_error',
    ];

    protected $casts = [
        'last_success_at' => 'datetime',
        'last_attempt_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
