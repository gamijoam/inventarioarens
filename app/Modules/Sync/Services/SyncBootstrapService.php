<?php

namespace App\Modules\Sync\Services;

use App\Modules\Sync\Models\SyncBootstrapSession;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SyncBootstrapService
{
    public function __construct(
        private readonly SyncTransportService $transport,
        private readonly SyncInitialSnapshotService $initialSnapshot,
        private readonly TenantManager $tenants,
    ) {}

    public function start(Tenant $tenant, array $data): array
    {
        $this->tenants->set($tenant);

        try {
            return DB::transaction(function () use ($tenant, $data): array {
                $node = $this->transport->registerNode([
                    'code' => $data['node_code'],
                    'name' => $data['node_name'],
                    'type' => 'local',
                    'status' => 'active',
                    'metadata' => [
                        'worker' => 'bootstrap',
                        'tenant' => $tenant->slug,
                        'installation_code' => $data['installation_code'],
                    ],
                ]);
                DB::table('sync_bootstrap_sessions')
                    ->where('tenant_id', $tenant->id)
                    ->where('target_node_id', (int) $node['id'])
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'superseded',
                        'last_error' => 'Reemplazada por un nuevo bootstrap.',
                        'updated_at' => now(),
                    ]);
                $cutoff = (int) (DB::table('sync_outbox')
                    ->where('tenant_id', $tenant->id)
                    ->max('id') ?? 0);
                $snapshotKey = $data['installation_code'].':session-'.Str::lower(Str::random(24));

                $this->initialSnapshot->queueForNode(
                    $tenant,
                    (int) $node['id'],
                    $data['installation_code'],
                    $snapshotKey,
                );

                $events = DB::table('sync_outbox')
                    ->where('tenant_id', $tenant->id)
                    ->where('target_node_id', (int) $node['id'])
                    ->where('idempotency_key', 'like', 'initial-snapshot:'.$snapshotKey.':node-'.$node['id'].':%')
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($event): array => $this->formatEvent((array) $event))
                    ->all();
                $plainToken = Str::random(80);
                $session = SyncBootstrapSession::query()->create([
                    'tenant_id' => $tenant->id,
                    'target_node_id' => (int) $node['id'],
                    'installation_code' => $data['installation_code'],
                    'snapshot_key' => $snapshotKey,
                    'session_token_hash' => hash('sha256', $plainToken),
                    'snapshot_cutoff_id' => $cutoff,
                    'snapshot_event_count' => count($events),
                    'status' => 'pending',
                    'expires_at' => now()->addHours(2),
                ]);

                return [
                    'tenant' => $this->tenantSummary($tenant),
                    'session' => [
                        'token' => $plainToken,
                        'status' => $session->status,
                        'expires_at' => $session->expires_at->toISOString(),
                    ],
                    'snapshot' => [
                        'version' => 1,
                        'cutoff_event_id' => $cutoff,
                        'event_count' => count($events),
                        'events' => $events,
                    ],
                ];
            });
        } finally {
            $this->tenants->clear();
        }
    }

    public function complete(Tenant $tenant, string $plainToken): array
    {
        $this->tenants->set($tenant);

        try {
            return DB::transaction(function () use ($tenant, $plainToken): array {
                /** @var SyncBootstrapSession|null $session */
                $session = SyncBootstrapSession::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('session_token_hash', hash('sha256', $plainToken))
                    ->lockForUpdate()
                    ->first();

                if (! $session) {
                    throw ValidationException::withMessages([
                        'session' => 'La sesion de bootstrap no existe para esta empresa.',
                    ]);
                }

                if ($session->status === 'completed') {
                    return $this->completionSummary($session);
                }

                if ($session->expires_at->isPast()) {
                    throw ValidationException::withMessages([
                        'session' => 'La sesion de bootstrap expiro.',
                    ]);
                }

                $updated = DB::table('sync_outbox')
                    ->where('tenant_id', $tenant->id)
                    ->where('target_node_id', $session->target_node_id)
                    ->where('idempotency_key', 'like', 'initial-snapshot:'.$session->snapshot_key.':node-'.$session->target_node_id.':%')
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'processed',
                        'processed_at' => now(),
                        'locked_at' => null,
                        'updated_at' => now(),
                    ]);

                $lastSnapshotEvent = DB::table('sync_outbox')
                    ->where('tenant_id', $tenant->id)
                    ->where('target_node_id', $session->target_node_id)
                    ->where('idempotency_key', 'like', 'initial-snapshot:'.$session->snapshot_key.':node-'.$session->target_node_id.':%')
                    ->orderByDesc('id')
                    ->first(['id', 'event_uuid']);

                if ($lastSnapshotEvent) {
                    $stateKeys = [
                        'tenant_id' => $tenant->id,
                        'node_id' => $session->target_node_id,
                        'direction' => 'pull',
                    ];
                    $statePayload = [
                        'last_event_id' => $lastSnapshotEvent->id,
                        'last_event_uuid' => $lastSnapshotEvent->event_uuid,
                        'last_success_at' => now(),
                        'last_attempt_at' => now(),
                        'last_error' => null,
                        'updated_at' => now(),
                    ];
                    $stateExists = DB::table('sync_states')->where($stateKeys)->exists();
                    DB::table('sync_states')->updateOrInsert(
                        $stateKeys,
                        $stateExists
                            ? $statePayload
                            : array_merge($statePayload, ['created_at' => now()]),
                    );
                }

                $session->forceFill([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'last_error' => null,
                ])->save();

                return [
                    'status' => $session->status,
                    'events_processed' => $updated,
                    'snapshot_cutoff_id' => $session->snapshot_cutoff_id,
                    'completed_at' => $session->completed_at->toISOString(),
                ];
            });
        } finally {
            $this->tenants->clear();
        }
    }

    private function completionSummary(SyncBootstrapSession $session): array
    {
        return [
            'status' => $session->status,
            'events_processed' => $session->snapshot_event_count,
            'snapshot_cutoff_id' => $session->snapshot_cutoff_id,
            'completed_at' => $session->completed_at?->toISOString(),
        ];
    }

    private function formatEvent(array $event): array
    {
        $event['payload'] = is_array($event['payload'] ?? null)
            ? $event['payload']
            : (json_decode((string) ($event['payload'] ?? ''), true) ?: []);

        foreach (['occurred_at', 'available_at', 'processed_at', 'created_at', 'updated_at'] as $field) {
            $event[$field] = ! empty($event[$field])
                ? Carbon::parse($event[$field])->toISOString()
                : null;
        }

        return $event;
    }

    private function tenantSummary(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'parent_id' => $tenant->parent_id,
            'is_group' => $tenant->isGroup(),
        ];
    }
}
