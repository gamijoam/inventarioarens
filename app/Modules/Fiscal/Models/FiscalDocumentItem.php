<?php

namespace App\Modules\Fiscal\Models;

use App\Modules\Sales\Models\SaleItem;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'fiscal_document_id',
    'sale_item_id',
    'quantity',
    'sale_currency',
    'unit_price',
    'total_amount',
    'base_unit_price',
    'base_total_amount',
    'local_total_amount',
    'product_snapshot',
    'warehouse_snapshot',
    'commercial_snapshot',
    'fiscal_snapshot',
])]
class FiscalDocumentItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'base_unit_price' => 'decimal:4',
            'base_total_amount' => 'decimal:4',
            'local_total_amount' => 'decimal:4',
            'product_snapshot' => 'array',
            'warehouse_snapshot' => 'array',
            'commercial_snapshot' => 'array',
            'fiscal_snapshot' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
