<?php

namespace App\Modules\Sync\Services;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncBootstrapImporter
{
    public function __construct(
        private readonly SyncEventApplier $applier,
        private readonly TenantManager $tenants,
    ) {}

    public function importSnapshot(Tenant $tenant, array $snapshot): array
    {
        if ((int) ($snapshot['version'] ?? 0) !== 1 || ! is_array($snapshot['events'] ?? null)) {
            throw ValidationException::withMessages([
                'snapshot' => 'El paquete de bootstrap no tiene un formato valido.',
            ]);
        }

        $previousTenant = $this->tenants->current();
        $this->tenants->set($tenant);

        try {
            return DB::transaction(function () use ($tenant, $snapshot): array {
                $eventUuids = [];

                foreach ($snapshot['events'] as $event) {
                    $eventUuid = (string) ($event['event_uuid'] ?? '');
                    if ($eventUuid === '') {
                        throw ValidationException::withMessages([
                            'snapshot' => 'El paquete contiene un evento sin event_uuid.',
                        ]);
                    }

                    $eventUuids[] = $eventUuid;
                    if (DB::table('sync_inbox')
                        ->where('tenant_id', $tenant->id)
                        ->where('event_uuid', $eventUuid)
                        ->exists()) {
                        continue;
                    }

                    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
                    $createdAt = $event['created_at'] ?? now();
                    $updatedAt = $event['updated_at'] ?? $createdAt;

                    DB::table('sync_inbox')->insert([
                        'tenant_id' => $tenant->id,
                        'event_uuid' => $eventUuid,
                        'origin_node_id' => null,
                        'event_type' => (string) ($event['event_type'] ?? ''),
                        'aggregate_type' => (string) ($event['aggregate_type'] ?? ''),
                        'aggregate_id' => $event['aggregate_id'] ?? null,
                        'payload_hash' => hash('sha256', json_encode($payload)),
                        'payload' => json_encode($payload),
                        'status' => 'received',
                        'received_at' => now(),
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                    ]);
                }

                $summary = $this->applier->applyEventUuids($tenant, $eventUuids);
                if ($summary['failed'] > 0) {
                    throw ValidationException::withMessages([
                        'snapshot' => 'No se pudo aplicar completamente el paquete de bootstrap.',
                    ]);
                }

                return [
                    'received' => count($eventUuids),
                    'applied' => $summary['applied'],
                    'ignored' => $summary['ignored'],
                    'failed' => $summary['failed'],
                ];
            });
        } finally {
            $previousTenant ? $this->tenants->set($previousTenant) : $this->tenants->clear();
        }
    }
}
