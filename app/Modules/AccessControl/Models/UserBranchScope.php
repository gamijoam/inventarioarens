<?php

namespace App\Modules\AccessControl\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBranchScope extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'branch_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
