<?php

namespace App\Modules\Currency\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'is_default', 'is_active'])]
class ExchangeRateType extends Model
{
    use BelongsToTenant;

    public function rates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class);
    }
}
