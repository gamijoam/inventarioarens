<?php

namespace App\Modules\Customers\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name',
    'document_type',
    'document_number',
    'phone',
    'email',
    'fiscal_address',
    'is_generic',
    'is_active',
    'customer_group_id',
    'zone_id',
])]
class Customer extends Model
{
    use BelongsToTenant;

    public const DOCUMENT_V = 'V';
    public const DOCUMENT_E = 'E';
    public const DOCUMENT_J = 'J';
    public const DOCUMENT_G = 'G';
    public const DOCUMENT_P = 'P';

    protected function casts(): array
    {
        return [
            'is_generic' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }
}
