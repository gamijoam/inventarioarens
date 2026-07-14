<?php

namespace App\Modules\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\Tenancy\Concerns\BelongsToTenant;
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

<?php

namespace App\Modules\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Tenant;

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

<?php

namespace App\Modules\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Tenant;

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

<?php

namespace App\Modules\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\Tenancy\Concerns\BelongsToTenant;
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

<?php

namespace App\Modules\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Tenant;

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

