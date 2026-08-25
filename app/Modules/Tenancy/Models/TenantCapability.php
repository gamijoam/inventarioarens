<?php

namespace App\Modules\Tenancy\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'capability', 'enabled'])]
class TenantCapability extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}
