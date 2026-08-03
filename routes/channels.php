<?php

use Illuminate\Support\Facades\Broadcast;
use App\Modules\InventoryTransferRequests\Broadcasting\TransferRequestChannel;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('tenant.{tenantId}', TransferRequestChannel::class);
