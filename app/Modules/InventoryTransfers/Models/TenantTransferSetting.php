<?php

namespace App\Modules\InventoryTransfers\Models;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'validation_mode',
    'reserve_on_request',
    'require_preparation_checklist',
    'require_reception_checklist',
    'settings',
])]
class TenantTransferSetting extends Model
{
    public const MODE_SIMPLE = 'simple';
    public const MODE_LOGISTICS = 'logistics';

    protected function casts(): array
    {
        return [
            'reserve_on_request' => 'boolean',
            'require_preparation_checklist' => 'boolean',
            'require_reception_checklist' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
