<?php

namespace App\Modules\Sync\Services;

use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncOutboxService
{
    public function record(
        string $eventType,
        string $aggregateType,
        ?int $aggregateId,
        array $payload,
        ?string $idempotencyKey = null,
        string $targetScope = 'tenant',
        ?int $targetNodeId = null,
    ): int {
        $tenant = app(TenantManager::class)->require();
        $idempotencyKey ??= $this->defaultIdempotencyKey($eventType, $aggregateType, $aggregateId);
        $now = now();

        $existingId = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->value('id');

        if ($existingId) {
            return (int) $existingId;
        }

        return (int) DB::table('sync_outbox')->insertGetId([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'origin_node_id' => null,
            'target_node_id' => $targetNodeId,
            'target_scope' => $targetScope,
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => json_encode($payload),
            'occurred_at' => $now,
            'available_at' => $now,
            'status' => 'pending',
            'idempotency_key' => $idempotencyKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function defaultIdempotencyKey(string $eventType, string $aggregateType, ?int $aggregateId): string
    {
        return implode(':', [
            $eventType,
            $aggregateType,
            $aggregateId ?? 'none',
        ]);
    }

}
