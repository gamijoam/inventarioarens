<?php

namespace App\Modules\Sync\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'target_tenant_id',
    'created_by_user_id',
    'target_user_id',
    'is_group_bundle',
    'code_hash',
    'node_name',
    'expires_at',
    'redeemed_at',
    'redeemed_node_code',
])]
class SyncPairingCode extends Model
{
    public function targetTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'target_tenant_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    protected function casts(): array
    {
        return [
            'is_group_bundle' => 'boolean',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }
}
