<?php

namespace App\Modules\Sync\Services;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SyncReadinessService
{
    private const STATUSES = ['pending', 'syncing', 'ready', 'warning', 'error'];

    public function get(string $installationCode): array
    {
        $tenant = app(TenantManager::class)->require();

        return $this->format($this->findOrCreate($tenant, $installationCode));
    }

    public function mark(string $installationCode, array $data): array
    {
        $tenant = app(TenantManager::class)->require();
        $status = $data['status'] ?? 'pending';

        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Estado de sincronizacion invalido.');
        }

        $now = $status === 'ready' ? now() : null;

        return $this->format($this->upsert($tenant, $installationCode, [
            'node_code' => $data['node_code'] ?? null,
            'node_name' => $data['node_name'] ?? null,
            'status' => $status,
            'last_success_at' => $now,
            'initial_sync_completed_at' => $now,
            'last_error' => $status === 'error' ? ($data['last_error'] ?? null) : null,
            'metadata' => $data['metadata'] ?? [],
        ]));
    }

    public function markSyncing(Tenant $tenant, string $installationCode, string $nodeCode, string $nodeName): void
    {
        $this->upsert($tenant, $installationCode, [
            'node_code' => $nodeCode,
            'node_name' => $nodeName,
            'status' => 'syncing',
            'last_error' => null,
        ]);
    }

    public function markCompleted(
        Tenant $tenant,
        string $installationCode,
        string $nodeCode,
        string $nodeName,
        array $summary,
    ): void {
        $failed = (int) ($summary['failed'] ?? 0);
        $now = now();

        $this->upsert($tenant, $installationCode, [
            'node_code' => $nodeCode,
            'node_name' => $nodeName,
            'status' => $failed > 0 ? 'warning' : 'ready',
            'last_push_at' => ((int) ($summary['pushed'] ?? 0)) > 0 ? $now : null,
            'last_pull_at' => ((int) ($summary['pulled'] ?? 0)) > 0 ? $now : null,
            'last_apply_at' => ((int) ($summary['applied'] ?? 0)) > 0 ? $now : null,
            'last_success_at' => $failed > 0 ? null : $now,
            'initial_sync_completed_at' => $failed > 0 ? null : $now,
            'last_error' => $failed > 0 ? 'La sincronizacion termino con fallos. Revisa el log tecnico.' : null,
            'metadata' => ['summary' => $summary],
        ]);
    }

    public function markFailed(Tenant $tenant, string $installationCode, string $nodeCode, string $nodeName, string $error): void
    {
        $this->upsert($tenant, $installationCode, [
            'node_code' => $nodeCode,
            'node_name' => $nodeName,
            'status' => 'error',
            'last_error' => $error,
        ]);
    }

    private function findOrCreate(Tenant $tenant, string $installationCode): array
    {
        $existing = DB::table('sync_tenant_readiness')
            ->where('tenant_id', $tenant->id)
            ->where('installation_code', $installationCode)
            ->first();

        if ($existing) {
            return (array) $existing;
        }

        return $this->upsert($tenant, $installationCode, [
            'status' => 'pending',
            'metadata' => [],
        ]);
    }

    private function upsert(Tenant $tenant, string $installationCode, array $data): array
    {
        $now = now();
        $keys = [
            'tenant_id' => $tenant->id,
            'installation_code' => $installationCode,
        ];

        $existing = DB::table('sync_tenant_readiness')->where($keys)->first();
        $payload = [
            'node_code' => $data['node_code'] ?? ($existing->node_code ?? null),
            'node_name' => $data['node_name'] ?? ($existing->node_name ?? null),
            'status' => $data['status'] ?? ($existing->status ?? 'pending'),
            'last_push_at' => $data['last_push_at'] ?? ($existing->last_push_at ?? null),
            'last_pull_at' => $data['last_pull_at'] ?? ($existing->last_pull_at ?? null),
            'last_apply_at' => $data['last_apply_at'] ?? ($existing->last_apply_at ?? null),
            'last_success_at' => $data['last_success_at'] ?? ($existing->last_success_at ?? null),
            'initial_sync_completed_at' => $data['initial_sync_completed_at'] ?? ($existing->initial_sync_completed_at ?? null),
            'last_error' => array_key_exists('last_error', $data) ? $data['last_error'] : ($existing->last_error ?? null),
            'metadata' => json_encode($data['metadata'] ?? $this->decodeJson($existing->metadata ?? null)),
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('sync_tenant_readiness')->where($keys)->update($payload);
        } else {
            DB::table('sync_tenant_readiness')->insert(array_merge($keys, $payload, ['created_at' => $now]));
        }

        return (array) DB::table('sync_tenant_readiness')->where($keys)->first();
    }

    private function format(array $row): array
    {
        $row['metadata'] = $this->decodeJson($row['metadata'] ?? null);

        foreach (['last_push_at', 'last_pull_at', 'last_apply_at', 'last_success_at', 'initial_sync_completed_at'] as $field) {
            $row[$field] = $row[$field] ? Carbon::parse($row[$field])->toISOString() : null;
        }

        return $row;
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
}
