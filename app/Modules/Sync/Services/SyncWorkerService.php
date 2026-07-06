<?php

namespace App\Modules\Sync\Services;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncWorkerService
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly HttpFactory $http,
        private readonly SyncTransportService $transport,
        private readonly SyncEventApplier $applier,
        private readonly SyncReadinessService $readiness,
    ) {
    }

    public function run(
        Tenant $tenant,
        string $nodeCode,
        string $nodeName,
        string $cloudUrl,
        string $token,
        int $limit = 50,
        bool $push = true,
        bool $pull = true,
        bool $apply = true,
        ?string $installationCode = null,
    ): array {
        $this->tenants->set($tenant);
        $installationCode ??= $nodeCode;

        try {
            $localNode = $this->transport->registerNode([
                'code' => $nodeCode,
                'name' => $nodeName,
                'type' => 'local',
                'status' => 'active',
                'metadata' => [
                    'worker' => 'artisan',
                    'tenant' => $tenant->slug,
                ],
            ]);

            $shouldRequestInitialSnapshot = $this->shouldRequestInitialSnapshot($tenant, $installationCode);

            $this->readiness->markSyncing($tenant, $installationCode, $nodeCode, $nodeName);

            $this->registerCloudNode($tenant, $nodeCode, $nodeName, $cloudUrl, $token, $installationCode, $shouldRequestInitialSnapshot);

            $summary = [
                'node_code' => $nodeCode,
                'pushed' => 0,
                'duplicated_on_cloud' => 0,
                'pulled' => 0,
                'acknowledged' => 0,
                'applied' => 0,
                'ignored' => 0,
                'failed' => 0,
            ];

            if ($push) {
                $pushSummary = $this->pushLocalEvents($tenant, $nodeCode, $cloudUrl, $token, $limit);
                $summary['pushed'] = $pushSummary['pushed'];
                $summary['duplicated_on_cloud'] = $pushSummary['duplicated_on_cloud'];
                $summary['failed'] += $pushSummary['failed'];
            }

            if ($pull) {
                $pullSummary = $this->pullCloudEvents($tenant, (int) $localNode['id'], $nodeCode, $cloudUrl, $token, $limit);
                $summary['pulled'] = $pullSummary['pulled'];
                $summary['acknowledged'] = $pullSummary['acknowledged'];
                $summary['failed'] += $pullSummary['failed'];
            }

            if ($apply) {
                $applySummary = $this->applier->applyPending($tenant, $limit);
                $summary['applied'] = $applySummary['applied'];
                $summary['ignored'] = $applySummary['ignored'];
                $summary['failed'] += $applySummary['failed'];
            }

            $this->readiness->markCompleted($tenant, $installationCode, $nodeCode, $nodeName, $summary);

            return $summary;
        } catch (RuntimeException $exception) {
            $this->readiness->markFailed($tenant, $installationCode, $nodeCode, $nodeName, $exception->getMessage());

            throw $exception;
        } finally {
            $this->tenants->clear();
        }
    }

    private function shouldRequestInitialSnapshot(Tenant $tenant, string $installationCode): bool
    {
        $hasCoreCatalog = DB::table('branches')->where('tenant_id', $tenant->id)->exists()
            && DB::table('warehouses')->where('tenant_id', $tenant->id)->exists()
            && DB::table('products')->where('tenant_id', $tenant->id)->exists();

        if (! $hasCoreCatalog) {
            return true;
        }

        $readiness = DB::table('sync_tenant_readiness')
            ->where('tenant_id', $tenant->id)
            ->where('installation_code', $installationCode)
            ->first();

        return ! $readiness || ! $readiness->initial_sync_completed_at;
    }

    private function registerCloudNode(
        Tenant $tenant,
        string $nodeCode,
        string $nodeName,
        string $cloudUrl,
        string $token,
        string $installationCode,
        bool $initialSnapshot,
    ): void
    {
        $response = $this->client($tenant, $token)
            ->post($this->url($cloudUrl, 'sync/nodes'), [
                'code' => $nodeCode,
                'name' => $nodeName,
                'type' => 'local',
                'status' => 'active',
                'metadata' => [
                    'worker' => 'artisan',
                    'tenant' => $tenant->slug,
                    'installation_code' => $installationCode,
                    'initial_snapshot' => $initialSnapshot,
                ],
            ]);

        $this->ensureSuccess($response->status(), $response->body(), 'No se pudo registrar el nodo en la nube.');
    }

    private function pushLocalEvents(Tenant $tenant, string $nodeCode, string $cloudUrl, string $token, int $limit): array
    {
        $events = DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            return ['pushed' => 0, 'duplicated_on_cloud' => 0, 'failed' => 0];
        }

        $payload = $events->map(fn ($event): array => [
            'event_uuid' => $event->event_uuid,
            'event_type' => $event->event_type,
            'aggregate_type' => $event->aggregate_type,
            'aggregate_id' => $event->aggregate_id,
            'payload' => $this->decodePayload($event->payload),
            'occurred_at' => Carbon::parse($event->occurred_at)->toISOString(),
        ])->all();

        $response = $this->client($tenant, $token)
            ->post($this->url($cloudUrl, 'sync/events/push'), [
                'origin_node_code' => $nodeCode,
                'events' => $payload,
            ]);

        $this->ensureSuccess($response->status(), $response->body(), 'No se pudieron enviar eventos locales a la nube.');

        $data = $response->json('data') ?? [];
        $now = now();
        $ids = $events->pluck('id')->all();

        DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $ids)
            ->update([
                'status' => 'processed',
                'processed_at' => $now,
                'locked_at' => null,
                'last_error' => null,
                'updated_at' => $now,
            ]);

        $this->touchState($tenant, $nodeCode, 'push', (int) $events->last()->id, $events->last()->event_uuid, null);

        return [
            'pushed' => (int) ($data['received'] ?? count($ids)),
            'duplicated_on_cloud' => (int) ($data['duplicated'] ?? 0),
            'failed' => 0,
        ];
    }

    private function pullCloudEvents(Tenant $tenant, int $nodeId, string $nodeCode, string $cloudUrl, string $token, int $limit): array
    {
        $response = $this->client($tenant, $token)
            ->get($this->url($cloudUrl, 'sync/events/pull'), [
                'node_code' => $nodeCode,
                'limit' => $limit,
            ]);

        $this->ensureSuccess($response->status(), $response->body(), 'No se pudieron consultar eventos pendientes en la nube.');

        $events = $response->json('data') ?? [];
        $pulled = 0;
        $acknowledged = 0;
        $failed = 0;
        $lastEventId = null;
        $lastEventUuid = null;

        foreach ($events as $event) {
            $eventUuid = (string) ($event['event_uuid'] ?? '');

            if ($eventUuid === '') {
                $failed++;

                continue;
            }

            $stored = $this->storeInboxEvent($tenant, $nodeId, $event);
            $pulled += $stored ? 1 : 0;
            $lastEventId = isset($event['id']) ? (int) $event['id'] : null;
            $lastEventUuid = $eventUuid;

            $ack = $this->client($tenant, $token)
                ->post($this->url($cloudUrl, "sync/events/{$eventUuid}/ack"), [
                    'node_code' => $nodeCode,
                    'status' => 'applied',
                ]);

            if ($ack->successful()) {
                $acknowledged++;
            } else {
                $failed++;
            }
        }

        if ($lastEventUuid) {
            $this->touchState($tenant, $nodeCode, 'pull', $lastEventId, $lastEventUuid, $failed > 0 ? 'Hay eventos sin confirmar en la nube.' : null);
        }

        return [
            'pulled' => $pulled,
            'acknowledged' => $acknowledged,
            'failed' => $failed,
        ];
    }

    private function storeInboxEvent(Tenant $tenant, int $nodeId, array $event): bool
    {
        $eventUuid = (string) $event['event_uuid'];

        $exists = DB::table('sync_inbox')
            ->where('tenant_id', $tenant->id)
            ->where('event_uuid', $eventUuid)
            ->exists();

        if ($exists) {
            return false;
        }

        $payload = $event['payload'] ?? [];
        $now = now();

        DB::table('sync_inbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'origin_node_id' => null,
            'event_type' => $event['event_type'],
            'aggregate_type' => $event['aggregate_type'],
            'aggregate_id' => $event['aggregate_id'] ?? null,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => json_encode($payload),
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('sync_nodes')->where('tenant_id', $tenant->id)->where('id', $nodeId)->update([
            'last_seen_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }

    private function touchState(Tenant $tenant, string $nodeCode, string $direction, ?int $eventId, ?string $eventUuid, ?string $error): void
    {
        $nodeId = DB::table('sync_nodes')
            ->where('tenant_id', $tenant->id)
            ->where('code', $nodeCode)
            ->value('id');

        if (! $nodeId) {
            return;
        }

        $now = now();
        $keys = [
            'tenant_id' => $tenant->id,
            'node_id' => $nodeId,
            'direction' => $direction,
        ];
        $payload = [
            'last_event_id' => $eventId,
            'last_event_uuid' => $eventUuid,
            'last_success_at' => $error ? null : $now,
            'last_attempt_at' => $now,
            'last_error' => $error,
            'updated_at' => $now,
        ];
        $exists = DB::table('sync_states')->where($keys)->exists();

        DB::table('sync_states')->updateOrInsert(
            $keys,
            $exists ? $payload : array_merge($payload, ['created_at' => $now])
        );
    }

    private function client(Tenant $tenant, string $token)
    {
        return $this->http
            ->timeout(30)
            ->acceptJson()
            ->withToken($token)
            ->withHeader('X-Tenant', $tenant->slug);
    }

    private function url(string $cloudUrl, string $path): string
    {
        return rtrim($cloudUrl, '/').'/'.ltrim($path, '/');
    }

    private function ensureSuccess(int $status, string $body, string $message): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        throw new RuntimeException($message.' Respuesta '.$status.': '.$body);
    }

    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || $payload === '') {
            return [];
        }

        return json_decode($payload, true) ?: [];
    }
}
