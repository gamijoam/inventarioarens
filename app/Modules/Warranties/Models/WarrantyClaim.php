<?php

namespace App\Modules\Warranties\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sale_id',
    'sale_item_id',
    'customer_id',
    'product_id',
    'product_unit_id',
    'replacement_product_unit_id',
    'status',
    'quantity',
    'customer_name',
    'customer_phone',
    'issue_description',
    'received_notes',
    'diagnosis',
    'resolution_type',
    'resolution_notes',
    'replacement_stock_movement_id',
    'received_by',
    'reviewed_by',
    'delivered_by',
    'resolved_by',
    'received_at',
    'reviewed_at',
    'delivered_at',
    'resolved_at',
])]
class WarrantyClaim extends Model
{
    use BelongsToTenant;

    public const STATUS_RECEIVED = 'received';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CLOSED = 'closed';

    public const RESOLUTION_REPAIR = 'repair';
    public const RESOLUTION_REPLACEMENT = 'replacement';
    public const RESOLUTION_REFUND = 'refund';
    public const RESOLUTION_REJECTED = 'rejected';
    public const RESOLUTION_PENDING_REVIEW = 'pending_review';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function replacementProductUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'replacement_product_unit_id');
    }

    public function replacementStockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'replacement_stock_movement_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
