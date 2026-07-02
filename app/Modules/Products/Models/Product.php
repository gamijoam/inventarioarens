<?php

namespace App\Modules\Products\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'sku', 'is_active'])]
class Product extends Model
{
    use BelongsToTenant;
}
