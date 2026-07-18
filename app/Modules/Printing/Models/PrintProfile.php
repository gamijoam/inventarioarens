<?php

namespace App\Modules\Printing\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'paper_width_mm',
    'characters_per_line',
    'header_text',
    'footer_text',
    'logo_text',
    'show_warranty_summary',
    'cut_paper',
    'open_cash_drawer',
    'copies',
    'is_default',
    'is_active',
])]
class PrintProfile extends Model
{
    use BelongsToTenant;

    public const WIDTH_58 = 58;

    public const WIDTH_80 = 80;

    protected function casts(): array
    {
        return [
            'paper_width_mm' => 'integer',
            'characters_per_line' => 'integer',
            'show_warranty_summary' => 'boolean',
            'cut_paper' => 'boolean',
            'open_cash_drawer' => 'boolean',
            'copies' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function stations(): HasMany
    {
        return $this->hasMany(PrinterStation::class);
    }
}
