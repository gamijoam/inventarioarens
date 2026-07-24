<?php

namespace App\Modules\AccessControl\Models;

use App\Models\User;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWarehouseScope extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'warehouse_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
