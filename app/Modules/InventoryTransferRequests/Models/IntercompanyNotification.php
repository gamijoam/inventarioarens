<?php

namespace App\Modules\InventoryTransferRequests\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'inventory_transfer_request_id',
    'event_type',
    'title',
    'message',
    'action_url',
    'actor_user_id',
    'metadata',
    'occurred_at',
])]
class IntercompanyNotification extends Model
{
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'is_read' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function transferRequest(): BelongsTo
    {
        return $this->belongsTo(InventoryTransferRequest::class, 'inventory_transfer_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(IntercompanyNotificationRead::class, 'notification_id');
    }
}
