<?php

namespace App\Modules\InventoryTransferRequests\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'notification_id', 'user_id', 'read_at'])]
class IntercompanyNotificationRead extends Model
{
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(IntercompanyNotification::class, 'notification_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
