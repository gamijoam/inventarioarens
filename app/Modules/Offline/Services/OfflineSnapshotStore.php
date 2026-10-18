<?php

namespace App\Modules\Offline\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class OfflineSnapshotStore
{
    public function root(): string
    {
        return storage_path('app/private/offline_snapshots');
    }

    public function metadataPath(string $id): string
    {
        $this->assertUuid($id);

        return $this->root().DIRECTORY_SEPARATOR.$id.'.json';
    }

    public function archivePath(string $id): string
    {
        $this->assertUuid($id);

        return $this->root().DIRECTORY_SEPARATOR.$id.'.zip';
    }

    public function purgeExpired(): void
    {
        $root = $this->root();
        File::ensureDirectoryExists($root, 0700, true);

        foreach (glob($root.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
            $id = basename($path, '.json');
            if (! Str::isUuid($id)) {
                continue;
            }

            $metadata = json_decode((string) file_get_contents($path), true);
            if (! is_array($metadata)) {
                continue;
            }

            $expiredAt = $metadata['expires_at'] ?? null;
            if (! $expiredAt && ! empty($metadata['created_at'])) {
                $expiredAt = date(DATE_ATOM, strtotime($metadata['created_at'].' +1 day'));
            }
            if (! $expiredAt || strtotime((string) $expiredAt) > time()) {
                continue;
            }

            @unlink($path);
            @unlink($this->archivePath($id));
            File::deleteDirectory($root.DIRECTORY_SEPARATOR.'.work-'.$id);
        }
    }

    public function create(string $id, int $tenantId, int $userId): array
    {
        $this->purgeExpired();
        File::ensureDirectoryExists($this->root(), 0770, true);

        $metadata = [
            'id' => $id,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => 'queued',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'message' => 'Preparando los datos de la empresa.',
        ];
        $this->save($id, $metadata);

        return $metadata;
    }

    public function load(string $id): ?array
    {
        $path = $this->metadataPath($id);
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    public function save(string $id, array $metadata): void
    {
        $path = $this->metadataPath($id);
        File::ensureDirectoryExists(dirname($path), 0770, true);

        $tmp = $path.'.'.Str::random(8).'.tmp';
        $written = file_put_contents($tmp, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        if ($written === false || ! rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('No se pudo guardar el estado de la exportacion.');
        }
        @chmod($path, 0660);
    }

    public function assertOwner(array $metadata, int $tenantId, int $userId): void
    {
        abort_unless(
            (int) ($metadata['tenant_id'] ?? 0) === $tenantId
            && (int) ($metadata['user_id'] ?? 0) === $userId,
            404,
            'La exportacion no existe.'
        );
    }

    public function findLatestForUser(int $tenantId, int $userId): ?array
    {
        $root = $this->root();
        $latest = null;
        $latestTime = 0;

        foreach (glob($root.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
            $metadata = json_decode((string) @file_get_contents($path), true);
            if (! is_array($metadata)) {
                continue;
            }
            if (
                (int) ($metadata['tenant_id'] ?? 0) === $tenantId
                && (int) ($metadata['user_id'] ?? 0) === $userId
                && ($metadata['status'] ?? null) === 'ready'
            ) {
                $created = strtotime((string) ($metadata['created_at'] ?? ''));
                if ($created > $latestTime) {
                    $archive = $this->archivePath((string) $metadata['id']);
                    if (is_file($archive)) {
                        $latestTime = $created;
                        $latest = $metadata;
                    }
                }
            }
        }

        return $latest;
    }

    private function assertUuid(string $id): void
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('Identificador de exportacion invalido.');
        }
    }
}
