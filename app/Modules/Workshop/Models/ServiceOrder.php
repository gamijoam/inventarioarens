<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Warranties\Models\WarrantyClaim;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Orden de servicio del Taller (reparaciones y garantias).
 *
 * @property int $id
 * @property string $order_number
 * @property string $type (repair|warranty)
 * @property string $status
 * @property string|null $resolution (workshop|exchange|return_supplier)
 * @property int|null $technician_id
 * @property int $warehouse_id
 * @property float $labor_base_amount
 * @property float $labor_local_amount
 * @property float $parts_base_amount
 * @property float $parts_local_amount
 * @property float $total_base_amount
 * @property float $total_local_amount
 */
#[Fillable([
    'order_number',
    'type',
    'warranty_claim_id',
    'customer_id',
    'customer_name',
    'customer_phone',
    'device_description',
    'issue_description',
    'diagnosis',
    'status',
    'priority',
    'resolution',
    'technician_id',
    'warehouse_id',
    'labor_base_amount',
    'labor_local_amount',
    'parts_base_amount',
    'parts_local_amount',
    'total_base_amount',
    'total_local_amount',
    'notes',
    'created_by',
    'received_at',
    'technician_assigned_at',
    'diagnosed_at',
    'completed_at',
    'delivered_at',
    'cancelled_at',
])]
class ServiceOrder extends Model
{
    use BelongsToTenant;

    protected $table = 'service_orders';

    public const TYPE_REPAIR = 'repair';

    public const TYPE_WARRANTY = 'warranty';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_DIAGNOSED = 'diagnosed';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_READY = 'ready';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const RESOLUTION_WORKSHOP = 'workshop';

    public const RESOLUTION_EXCHANGE = 'exchange';

    public const RESOLUTION_RETURN_SUPPLIER = 'return_supplier';

    public const PART_STATUS_PENDING = 'pending';

    public const PART_STATUS_CONSUMED = 'consumed';

    public const PART_STATUS_RETURNED = 'returned';

    public const RESOLUTIONS = [
        self::RESOLUTION_WORKSHOP,
        self::RESOLUTION_EXCHANGE,
        self::RESOLUTION_RETURN_SUPPLIER,
    ];

    /**
     * Transiciones de estado permitidas.
     */
    public const STATUS_TRANSITIONS = [
        self::STATUS_RECEIVED => [self::STATUS_DIAGNOSED, self::STATUS_CANCELLED],
        self::STATUS_DIAGNOSED => [self::STATUS_IN_PROGRESS, self::STATUS_READY, self::STATUS_DELIVERED, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_READY, self::STATUS_DELIVERED, self::STATUS_CANCELLED],
        self::STATUS_READY => [self::STATUS_DELIVERED, self::STATUS_CANCELLED],
        self::STATUS_DELIVERED => [self::STATUS_CLOSED],
        self::STATUS_CLOSED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected function casts(): array
    {
        return [
            'labor_base_amount' => 'decimal:4',
            'labor_local_amount' => 'decimal:4',
            'parts_base_amount' => 'decimal:4',
            'parts_local_amount' => 'decimal:4',
            'total_base_amount' => 'decimal:4',
            'total_local_amount' => 'decimal:4',
            'received_at' => 'datetime',
            'technician_assigned_at' => 'datetime',
            'diagnosed_at' => 'datetime',
            'completed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public static function nextOrderNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('order_number');
        $sequence = $last ? ((int) substr((string) $last, -6)) + 1 : 1;

        return 'SO-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    public function canTransitionTo(string $to): bool
    {
        return in_array($to, static::STATUS_TRANSITIONS[$this->status] ?? [], true);
    }

    public function warrantyClaim(): BelongsTo
    {
        return $this->belongsTo(WarrantyClaim::class, 'warranty_claim_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(ServiceOrderPart::class, 'service_order_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ServiceOrderStatusHistory::class, 'service_order_id');
    }
}
