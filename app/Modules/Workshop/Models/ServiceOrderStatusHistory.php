<?php

namespace App\Modules\Workshop\Models;

use App\Models\User;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial de cambios de estado de una orden de servicio.
 */
#[Fillable(['service_order_id', 'from_status', 'to_status', 'changed_by', 'changed_at'])]
class ServiceOrderStatusHistory extends Model
{
    use BelongsToTenant;

    protected $table = 'service_order_status_history';

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
