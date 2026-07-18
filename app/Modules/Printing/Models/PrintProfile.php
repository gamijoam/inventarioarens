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
    'warranty_policy_text',
    'legal_text',
    'logo_text',
    'show_tenant_slug',
    'show_sale_number',
    'show_paid_at',
    'show_cashier',
    'show_cash_register',
    'show_branch',
    'show_customer',
    'show_item_sku',
    'show_item_discount',
    'show_item_serials',
    'show_warranty_summary',
    'show_total_local',
    'show_payment_rate',
    'show_payment_reference',
    'show_receivable_balance',
    'show_non_fiscal_text',
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
            'show_tenant_slug' => 'boolean',
            'show_sale_number' => 'boolean',
            'show_paid_at' => 'boolean',
            'show_cashier' => 'boolean',
            'show_cash_register' => 'boolean',
            'show_branch' => 'boolean',
            'show_customer' => 'boolean',
            'show_item_sku' => 'boolean',
            'show_item_discount' => 'boolean',
            'show_item_serials' => 'boolean',
            'show_total_local' => 'boolean',
            'show_payment_rate' => 'boolean',
            'show_payment_reference' => 'boolean',
            'show_receivable_balance' => 'boolean',
            'show_non_fiscal_text' => 'boolean',
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
