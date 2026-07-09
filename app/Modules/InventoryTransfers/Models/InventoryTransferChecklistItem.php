<?php

namespace App\Modules\InventoryTransfers\Models;

use App\Modules\Products\Models\Product;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'inventory_transfer_checklist_id',
    'inventory_transfer_item_id',
    'product_id',
    'expected_quantity',
    'checked_quantity',
    'difference_quantity',
    'reason',
    'notes',
    'expected_product_unit_ids',
    'checked_product_unit_ids',
])]
class InventoryTransferChecklistItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'expected_quantity' => 'decimal:4',
            'checked_quantity' => 'decimal:4',
            'difference_quantity' => 'decimal:4',
            'expected_product_unit_ids' => 'array',
            'checked_product_unit_ids' => 'array',
        ];
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(InventoryTransferChecklist::class, 'inventory_transfer_checklist_id');
    }

    public function transferItem(): BelongsTo
    {
        return $this->belongsTo(InventoryTransferItem::class, 'inventory_transfer_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
