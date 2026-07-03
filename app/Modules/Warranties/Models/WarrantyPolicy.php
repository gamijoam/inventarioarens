<?php

namespace App\Modules\Warranties\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'duration_days',
    'coverage_type',
    'conditions',
    'is_active',
])]
class WarrantyPolicy extends Model
{
    use BelongsToTenant;

    public const COVERAGE_STORE = 'store';
    public const COVERAGE_MANUFACTURER = 'manufacturer';
    public const COVERAGE_NONE = 'none';

    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
