<?php

namespace App\Modules\Suppliers\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'document_type',
    'document_number',
    'phone',
    'email',
    'fiscal_address',
    'notes',
    'is_active',
])]
class Supplier extends Model
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
            'is_active' => 'boolean',
        ];
    }
}
