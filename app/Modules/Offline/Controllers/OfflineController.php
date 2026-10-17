<?php

namespace App\Modules\Offline\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Offline\Jobs\BuildOfflineSnapshot;
use App\Modules\Offline\Services\OfflineSnapshotStore;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OfflineController extends Controller
{
    public function installerInfo(Request $request): JsonResponse
    {
        $this->authorizedTenant($request);
        $artifact = public_path('downloads/BalanzaPro-Setup.exe');
        $manifestPath = public_path('downloads/BalanzaPro-Setup.manifest.json');
        $manifest = is_file($manifestPath)
            ? json_decode((string) file_get_contents($manifestPath), true)
            : null;

        $expectedHash = strtolower((string) ($manifest['sha256'] ?? ''));
        $actualHash = is_file($artifact) ? hash_file('sha256', $artifact) : false;
        $available = is_file($artifact)
            && is_array($manifest)
            && ($manifest['name'] ?? null) === 'BalanzaPro-Setup.exe'
            && (int) ($manifest['size_bytes'] ?? -1) === (int) filesize($artifact)
            && preg_match('/^[a-f0-9]{64}$/', $expectedHash) === 1
            && is_string($actualHash)
            && hash_equals($expectedHash, strtolower($actualHash));

        $builtAt = $available ? ($manifest['built_at'] ?? null) : null;
        if (is_string($builtAt) && preg_match('/^\d{8}T\d{6}Z$/', $builtAt) === 1) {
            $parsedBuiltAt = \DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $builtAt, new \DateTimeZone('UTC'));
            if ($parsedBuiltAt && $parsedBuiltAt->format('Ymd\THis\Z') === $builtAt) {
                $builtAt = $parsedBuiltAt->format(DATE_ATOM);
            }
        }

        return response()->json([
            'available' => $available,
            'name' => $available ? 'BalanzaPro-Setup.exe' : null,
            'version' => $available ? ($manifest['version'] ?? null) : null,
            'size_bytes' => $available ? (int) $manifest['size_bytes'] : null,
            'built_at' => $builtAt,
            'sha256' => $available ? ($manifest['sha256'] ?? null) : null,
            'url' => $available ? '/downloads/BalanzaPro-Setup.exe' : null,
        ]);
    }

    public function createSnapshot(Request $request, OfflineSnapshotStore $store): JsonResponse
    {
        $tenant = $this->authorizedTenant($request);
        $snapshotId = (string) Str::uuid();
        $metadata = $store->create($snapshotId, (int) $tenant->id, (int) $request->user()->id);

        BuildOfflineSnapshot::dispatch((int) $tenant->id, (int) $request->user()->id, $snapshotId)
            ->onQueue('default');

        return response()->json([
            'id' => $snapshotId,
            'status' => $metadata['status'],
            'message' => $metadata['message'],
            'status_url' => url('/api/offline/snapshots/'.$snapshotId),
            'download_url' => url('/api/offline/snapshots/'.$snapshotId.'/download'),
        ], 202);
    }

    public function snapshotStatus(Request $request, string $snapshotId, OfflineSnapshotStore $store): JsonResponse
    {
        $tenant = $this->authorizedTenant($request);
        $metadata = $store->load($snapshotId);
        abort_unless($metadata, 404, 'La exportacion no existe.');
        $store->assertOwner($metadata, (int) $tenant->id, (int) $request->user()->id);

        return response()->json([
            'id' => $snapshotId,
            'status' => $metadata['status'] ?? 'failed',
            'message' => $metadata['message'] ?? 'No se pudo leer el estado.',
            'file_name' => $metadata['file_name'] ?? null,
            'size_bytes' => $metadata['size_bytes'] ?? null,
            'sha256' => $metadata['sha256'] ?? null,
            'expires_at' => $metadata['expires_at'] ?? null,
        ]);
    }

    public function downloadSnapshot(Request $request, string $snapshotId, OfflineSnapshotStore $store): BinaryFileResponse
    {
        $tenant = $this->authorizedTenant($request);
        $metadata = $store->load($snapshotId);
        abort_unless($metadata, 404, 'La exportacion no existe.');
        $store->assertOwner($metadata, (int) $tenant->id, (int) $request->user()->id);
        abort_unless(($metadata['status'] ?? null) === 'ready', 409, 'La exportacion aun no esta lista.');
        abort_if(isset($metadata['expires_at']) && now()->greaterThan($metadata['expires_at']), 410, 'El paquete vencio. Genera uno nuevo.');

        $archive = $store->archivePath($snapshotId);
        abort_unless(is_file($archive), 404, 'El paquete ya no esta disponible.');

        return response()->download($archive, (string) ($metadata['file_name'] ?? 'BalanzaPro-datos.zip'), [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(false);
    }

    public function legacyDownload(Request $request): JsonResponse
    {
        $this->authorizedTenant($request);

        return response()->json([
            'message' => 'Esta descarga anterior fue reemplazada. Solicita un nuevo paquete desde Configuracion de la empresa.',
            'replacement' => '/api/offline/snapshots',
        ], 410);
    }

    public function legacyUpload(Request $request): JsonResponse
    {
        $this->authorizedTenant($request);

        return response()->json([
            'message' => 'La carga de ventas a la nube esta deshabilitada para el modo local.',
        ], 410);
    }

    private function authorizedTenant(Request $request): Tenant
    {
        $user = $request->user();
        abort_unless($user, 401, 'Debes iniciar sesion.');
        abort_unless($user->can('settings.manage'), 403, 'No tienes permiso para exportar los datos de esta empresa.');

        return app(TenantManager::class)->require();
    }
}
