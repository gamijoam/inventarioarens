<?php

namespace App\Modules\CashRegister\Models;

use App\Modules\Branches\Models\Branch;
use App\Support\Sync\Syncable;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id',
    'name',
    'code',
    'status',
    'notes',
])]
class CashRegister extends Model
{
    use BelongsToTenant, Syncable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected function syncOutboxMethod(string $action): ?string
    {
        return match ($action) {
            'created' => 'cashRegisterCreated',
            'updated' => 'cashRegisterUpdated',
            default => null,
        };
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashRegisterSession::class);
    }

    public function openSession()
    {
        return $this->hasOne(CashRegisterSession::class)
            ->where('status', CashRegisterSession::STATUS_OPEN);
    }
}
