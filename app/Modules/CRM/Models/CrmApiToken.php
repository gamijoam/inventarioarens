<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'tenant_scope',
    'created_by',
    'name',
    'token_prefix',
    'token_hash',
    'scopes',
    'branch_ids',
    'warehouse_ids',
    'last_used_at',
    'expires_at',
    'revoked_at',
])]
class CrmApiToken extends Model
{
    use BelongsToTenant;

    public const SCOPE_CATALOG_READ = 'catalog.read';

    public const SCOPE_INVENTORY_READ = 'inventory.read';

    public const SCOPE_BRANCHES_READ = 'branches.read';

    public const SCOPES = [
        self::SCOPE_CATALOG_READ,
        self::SCOPE_INVENTORY_READ,
        self::SCOPE_BRANCHES_READ,
    ];

    public const TENANT_SCOPE_TENANT = 'tenant';

    public const TENANT_SCOPE_SUBTREE = 'subtree';

    public const TENANT_SCOPES = [
        self::TENANT_SCOPE_TENANT,
        self::TENANT_SCOPE_SUBTREE,
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at?->isFuture() === true;
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'branch_ids' => 'array',
            'warehouse_ids' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
