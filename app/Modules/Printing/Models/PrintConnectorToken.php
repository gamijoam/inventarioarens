<?php

namespace App\Modules\Printing\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'print_connector_id',
    'token_hash',
    'expires_at',
    'last_used_at',
    'revoked_at',
])]
class PrintConnectorToken extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(PrintConnector::class, 'print_connector_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
