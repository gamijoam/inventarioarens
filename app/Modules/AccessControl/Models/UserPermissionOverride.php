<?php

namespace App\Modules\AccessControl\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermissionOverride extends Model
{
    use BelongsToTenant;

    public const EFFECT_ALLOW = 'allow';

    public const EFFECT_DENY = 'deny';

    public const EFFECTS = [self::EFFECT_ALLOW, self::EFFECT_DENY];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'permission',
        'effect',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
