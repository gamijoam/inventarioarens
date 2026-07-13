<?php

namespace App\Modules\AccessControl\Models;

use App\Models\User;
use App\Modules\Customers\Models\CustomerGroup;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserVendorAssignment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'customer_group_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }
}