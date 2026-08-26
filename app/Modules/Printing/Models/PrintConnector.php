<?php

namespace App\Modules\Printing\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid',
    'name',
    'installation_id',
    'version',
    'status',
    'last_seen_at',
    'metadata',
])]
class PrintConnector extends Model
{
    use BelongsToTenant;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(PrintConnectorToken::class);
    }

    public function pairingCodes(): HasMany
    {
        return $this->hasMany(PrintConnectorPairingCode::class);
    }

    public function stations(): HasMany
    {
        return $this->hasMany(PrinterStation::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
