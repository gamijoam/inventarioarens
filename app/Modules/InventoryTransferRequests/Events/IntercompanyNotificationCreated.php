<?php

namespace App\Modules\InventoryTransferRequests\Events;

use App\Modules\InventoryTransferRequests\Models\IntercompanyNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class IntercompanyNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public readonly array $payload) {}

    public static function fromModel(IntercompanyNotification $notification): self
    {
        return new self([
            'id' => $notification->id,
            'tenant_id' => $notification->tenant_id,
            'inventory_transfer_request_id' => $notification->inventory_transfer_request_id,
            'event_type' => $notification->event_type,
            'title' => $notification->title,
            'message' => $notification->message,
            'action_url' => $notification->action_url,
            'occurred_at' => $notification->occurred_at?->toISOString(),
        ]);
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->payload['tenant_id'])];
    }

    public function broadcastAs(): string
    {
        return 'inventory-transfer-notifications.created';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
