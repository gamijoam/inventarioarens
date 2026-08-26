<?php

namespace App\Modules\Printing\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'code_hash',
    'created_by',
    'print_connector_id',
    'expires_at',
    'used_at',
])]
class PrintConnectorPairingCode extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(PrintConnector::class, 'print_connector_id');
    }
}
