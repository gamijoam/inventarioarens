<?php

namespace App\Modules\Sync\Services;

use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncTransportService
{
    public function __construct(
        private readonly SyncEventApplier $applier,
        private readonly SyncInitialSnapshotService $initialSnapshot,
    ) {}

    public function registerNode(array $data): array
    {
        $tenant = app(TenantManager::class)->require();
        $now = now();

        $nodeId = DB::table('sync_nodes')->where('tenant_id', $tenant->id)
            ->where('code', $data['code'])
            ->value('id');

        $payload = [
            'name' => $data['name'],
            'type' => $data['type'] ?? 'local',
            'status' => $data['status'] ?? 'active',
            'branch_id' => $data['branch_id'] ?? null,
            'last_seen_at' => $now,
            'metadata' => json_encode($data['metadata'] ?? []),
            'updated_at' => $now,
        ];

        if ($nodeId) {
            DB::table('sync_nodes')->where('tenant_id', $tenant->id)->where('id', $nodeId)->update($payload);
        } else {
            $nodeId = DB::table('sync_nodes')->insertGetId(array_merge($payload, [
                'tenant_id' => $tenant->id,
                'code' => $data['code'],
                'created_at' => $now,
            ]));
        }

        if (($data['metadata']['initial_snapshot'] ?? false) === true) {
            $installationCode = (string) ($data['metadata']['installation_code'] ?? $data['code']);
            $this->initialSnapshot->queueForNode($tenant, (int) $nodeId, $installationCode);
        }

        return $this->node((int) $nodeId);
    }

    public function pushEvents(array $events, ?string $originNodeCode = null): array
    {
        $tenant = app(TenantManager::class)->require();
        $originNode = $originNodeCode ? $this->findNodeByCode($originNodeCode) : null;
        $now = now();
        $received = 0;
        $duplicated = 0;
        $applied = 0;
        $ignored = 0;
        $failed = 0;
        $receivedEvents = [];

        foreach ($events as $event) {
            $exists = DB::table('sync_inbox')
                ->where('tenant_id', $tenant->id)
                ->where('event_uuid', $event['event_uuid'])
                ->exists();

            if ($exists) {
                $duplicated++;

                continue;
            }

            DB::table('sync_inbox')->insert([
                'tenant_id' => $tenant->id,
                'event_uuid' => $event['event_uuid'],
                'origin_node_id' => $originNode['id'] ?? null,
                'event_type' => $event['event_type'],
                'aggregate_type' => $event['aggregate_type'],
                'aggregate_id' => $event['aggregate_id'] ?? null,
                'payload_hash' => hash('sha256', json_encode($event['payload'] ?? [])),
                'payload' => json_encode($event['payload'] ?? []),
                'status' => 'received',
                'received_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $receivedEvents[] = $event;
            $received++;
        }

        if ($received > 0) {
            $applySummary = $this->applier->applyEventUuids(
                $tenant,
                array_map(fn (array $event): string => (string) $event['event_uuid'], $receivedEvents)
            );
            $applied = $applySummary['applied'];
            $ignored = $applySummary['ignored'];
            $failed = $applySummary['failed'];

            $this->mirrorAppliedEventsToOutbox($receivedEvents, $originNode['id'] ?? null, $now);
        }

        if ($originNode) {
            $this->touchState((int) $originNode['id'], 'push', null, null, null);
        }

        return [
            'received' => $received,
            'duplicated' => $duplicated,
            'applied' => $applied,
            'ignored' => $ignored,
            'failed' => $failed,
        ];
    }

    public function pullEvents(string $nodeCode, int $limit = 50): array
    {
        $node = $this->requireNodeByCode($nodeCode);
        $tenant = app(TenantManager::class)->require();
        $now = now();

        $events = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->where(function ($query) use ($now): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', $now);
            })
            ->where(function ($query) use ($node): void {
                $query->whereNull('target_node_id')->orWhere('target_node_id', $node['id']);
            })
            ->where(function ($query) use ($node): void {
                $query->whereNull('origin_node_id')->orWhere('origin_node_id', '<>', $node['id']);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $ids = $events->pluck('id')->all();

        if ($ids !== []) {
            DB::table('sync_outbox')
                ->where('tenant_id', $tenant->id)
                ->whereIn('id', $ids)
                ->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'locked_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        return $events->map(fn ($event): array => $this->formatOutboxEvent((array) $event))->all();
    }

    public function acknowledge(string $eventUuid, string $nodeCode, string $status = 'applied', ?string $error = null): array
    {
        $node = $this->requireNodeByCode($nodeCode);
        $tenant = app(TenantManager::class)->require();
        $now = now();

        $event = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_uuid', $eventUuid)
            ->first();

        abort_unless($event, 404, 'Evento de sincronizacion no encontrado.');

        $finalStatus = $status === 'failed' ? 'failed' : 'processed';

        DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_uuid', $eventUuid)
            ->update([
                'status' => $finalStatus,
                'processed_at' => $status === 'failed' ? null : $now,
                'last_error' => $error,
                'locked_at' => null,
                'updated_at' => $now,
            ]);

        $this->touchState((int) $node['id'], 'pull', (int) $event->id, $eventUuid, $status === 'failed' ? $error : null);

        return $this->formatOutboxEvent((array) DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_uuid', $eventUuid)
            ->first());
    }

    public function status(?string $nodeCode = null): array
    {
        $tenant = app(TenantManager::class)->require();
        $node = $nodeCode ? $this->requireNodeByCode($nodeCode) : null;

        return [
            'nodes' => DB::table('sync_nodes')->where('tenant_id', $tenant->id)->count(),
            'outbox' => [
                'pending' => DB::table('sync_outbox')->where('tenant_id', $tenant->id)->where('status', 'pending')->count(),
                'processed' => DB::table('sync_outbox')->where('tenant_id', $tenant->id)->where('status', 'processed')->count(),
                'failed' => DB::table('sync_outbox')->where('tenant_id', $tenant->id)->where('status', 'failed')->count(),
            ],
            'inbox' => [
                'received' => DB::table('sync_inbox')->where('tenant_id', $tenant->id)->where('status', 'received')->count(),
                'applied' => DB::table('sync_inbox')->where('tenant_id', $tenant->id)->where('status', 'applied')->count(),
                'failed' => DB::table('sync_inbox')->where('tenant_id', $tenant->id)->where('status', 'failed')->count(),
            ],
            'node' => $node,
            'states' => $node ? DB::table('sync_states')
                ->where('tenant_id', $tenant->id)
                ->where('node_id', $node['id'])
                ->orderBy('direction')
                ->get()
                ->map(fn ($state): array => (array) $state)
                ->all() : [],
            'latest_events' => [
                'outbox' => DB::table('sync_outbox')
                    ->where('tenant_id', $tenant->id)
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get()
                    ->map(fn ($event): array => $this->formatOutboxEvent((array) $event))
                    ->all(),
                'inbox' => DB::table('sync_inbox')
                    ->where('tenant_id', $tenant->id)
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get()
                    ->map(fn ($event): array => $this->formatInboxEvent((array) $event))
                    ->all(),
            ],
        ];
    }

    private function touchState(int $nodeId, string $direction, ?int $eventId, ?string $eventUuid, ?string $error): void
    {
        $tenant = app(TenantManager::class)->require();
        $now = now();

        $stateKeys = [
            'tenant_id' => $tenant->id,
            'node_id' => $nodeId,
            'direction' => $direction,
        ];

        $statePayload = [
            'last_event_id' => $eventId,
            'last_event_uuid' => $eventUuid,
            'last_success_at' => $error ? null : $now,
            'last_attempt_at' => $now,
            'last_error' => $error,
            'updated_at' => $now,
        ];

        $exists = DB::table('sync_states')->where($stateKeys)->exists();

        DB::table('sync_states')->updateOrInsert(
            $stateKeys,
            $exists ? $statePayload : array_merge($statePayload, ['created_at' => $now])
        );

        DB::table('sync_nodes')->where('tenant_id', $tenant->id)->where('id', $nodeId)->update([
            'last_seen_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function mirrorAppliedEventsToOutbox(array $events, ?int $originNodeId, Carbon $now): void
    {
        $tenant = app(TenantManager::class)->require();
        $eventUuids = array_values(array_filter(array_map(
            fn (array $event): string => (string) ($event['event_uuid'] ?? ''),
            $events
        )));

        if ($eventUuids === []) {
            return;
        }

        $relayableUuids = DB::table('sync_inbox')
            ->where('tenant_id', $tenant->id)
            ->whereIn('event_uuid', $eventUuids)
            ->whereIn('status', ['applied', 'ignored'])
            ->pluck('event_uuid')
            ->flip();

        foreach ($events as $event) {
            if (! $relayableUuids->has((string) $event['event_uuid'])) {
                continue;
            }

            $this->mirrorReceivedEventToOutbox($event, $originNodeId, $now);
        }
    }

    private function mirrorReceivedEventToOutbox(array $event, ?int $originNodeId, Carbon $now): void
    {
        $tenant = app(TenantManager::class)->require();

        if (DB::table('sync_outbox')->where('tenant_id', $tenant->id)->where('event_uuid', $event['event_uuid'])->exists()) {
            return;
        }

        DB::table('sync_outbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $event['event_uuid'],
            'origin_node_id' => $originNodeId,
            'target_node_id' => null,
            'target_scope' => 'tenant',
            'event_type' => $event['event_type'],
            'aggregate_type' => $event['aggregate_type'],
            'aggregate_id' => $event['aggregate_id'] ?? null,
            'payload' => json_encode($event['payload'] ?? []),
            'occurred_at' => isset($event['occurred_at']) ? Carbon::parse($event['occurred_at']) : $now,
            'available_at' => $now,
            'status' => 'pending',
            'idempotency_key' => 'relay:'.($event['event_uuid']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function requireNodeByCode(string $code): array
    {
        $node = $this->findNodeByCode($code);

        abort_unless($node && $node['status'] === 'active', 404, 'Nodo de sincronizacion no encontrado o inactivo.');

        return $node;
    }

    private function findNodeByCode(string $code): ?array
    {
        $tenant = app(TenantManager::class)->require();
        $node = DB::table('sync_nodes')->where('tenant_id', $tenant->id)->where('code', $code)->first();

        return $node ? $this->formatNode((array) $node) : null;
    }

    private function node(int $id): array
    {
        $tenant = app(TenantManager::class)->require();

        return $this->formatNode((array) DB::table('sync_nodes')->where('tenant_id', $tenant->id)->where('id', $id)->first());
    }

    private function formatNode(array $node): array
    {
        $node['metadata'] = $this->decodeJson($node['metadata'] ?? null);

        return $node;
    }

    private function formatOutboxEvent(array $event): array
    {
        $event['payload'] = $this->decodeJson($event['payload'] ?? null);
        $event['occurred_at'] = $this->formatDate($event['occurred_at'] ?? null);
        $event['available_at'] = $this->formatDate($event['available_at'] ?? null);
        $event['processed_at'] = $this->formatDate($event['processed_at'] ?? null);

        return $event;
    }

    private function formatInboxEvent(array $event): array
    {
        $event['payload'] = $this->decodeJson($event['payload'] ?? null);
        $event['received_at'] = $this->formatDate($event['received_at'] ?? null);
        $event['applied_at'] = $this->formatDate($event['applied_at'] ?? null);

        return $event;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        return json_decode($value, true) ?: [];
    }

    private function formatDate(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toISOString() : null;
    }
}
