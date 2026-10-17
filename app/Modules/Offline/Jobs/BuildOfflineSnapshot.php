<?php

namespace App\Modules\Offline\Jobs;

use App\Modules\Offline\Services\OfflineSnapshotExporter;
use App\Modules\Offline\Services\OfflineSnapshotStore;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class BuildOfflineSnapshot implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly string $snapshotId,
    ) {}

    public function handle(OfflineSnapshotExporter $exporter, OfflineSnapshotStore $store): void
    {
        $metadata = $store->load($this->snapshotId);
        if (! $metadata) {
            return;
        }

        $metadata['status'] = 'building';
        $metadata['updated_at'] = now()->toIso8601String();
        $metadata['message'] = 'Generando una copia aislada y verificando su integridad.';
        $store->save($this->snapshotId, $metadata);

        try {
            $tenant = Tenant::query()->findOrFail($this->tenantId);
            $result = $exporter->build($tenant, $this->snapshotId);
            $metadata['status'] = 'ready';
            $metadata['updated_at'] = now()->toIso8601String();
            $metadata['message'] = 'La descarga esta lista.';
            $metadata['file_name'] = $result['file_name'];
            $metadata['size_bytes'] = $result['size_bytes'];
            $metadata['sha256'] = $result['sha256'];
            $metadata['expires_at'] = now()->addDay()->toIso8601String();
            $store->save($this->snapshotId, $metadata);
        } catch (Throwable $exception) {
            @unlink($store->archivePath($this->snapshotId));
            $metadata['status'] = 'failed';
            $metadata['updated_at'] = now()->toIso8601String();
            $metadata['message'] = 'No se pudo preparar el paquete. Reintenta o contacta al administrador.';
            $store->save($this->snapshotId, $metadata);

            Log::error('No se pudo generar el paquete offline de una empresa.', [
                'snapshot_id' => $this->snapshotId,
                'tenant_id' => $this->tenantId,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(OfflineSnapshotStore::class)->save($this->snapshotId, [
            'id' => $this->snapshotId,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'status' => 'failed',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'message' => 'No se pudo preparar el paquete. Reintenta o contacta al administrador.',
        ]);
    }
}
