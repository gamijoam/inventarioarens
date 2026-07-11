<?php
/**
 * Backfill one-off: encola el evento inventory_transfer.created para el
 * traslado id=1 que ya existe en la DB local pero que nunca se sincronizo
 * a la nube (regresion del 2026-07-10).
 *
 * Idempotente: si el evento ya esta en el outbox (status pending o processed),
 * no hace nada.
 *
 * Uso: php scripts/backfill-transfer-sync.php [transfer_id]
 *      (default: 1)
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

$transferId = (int) ($argv[1] ?? 1);

$transfer = InventoryTransfer::query()->find($transferId);
if (! $transfer) {
    fwrite(STDERR, "No se encontro el traslado id={$transferId}.\n");
    exit(1);
}

$tenant = Tenant::query()->find($transfer->tenant_id);
if (! $tenant) {
    fwrite(STDERR, "El traslado id={$transferId} no tiene tenant valido (tenant_id={$transfer->tenant_id}).\n");
    exit(1);
}

app(TenantManager::class)->set($tenant);

$existing = DB::table('sync_outbox')
    ->where('aggregate_type', 'inventory_transfer')
    ->where('aggregate_id', $transfer->id)
    ->whereIn('event_type', ['inventory_transfer.created', 'inventory_transfer.updated'])
    ->whereIn('status', ['pending', 'processed'])
    ->first();

if ($existing) {
    echo "El traslado id={$transfer->id} ya tiene un evento inventory_transfer en el outbox (status={$existing->status}). Nada que hacer.\n";
    exit(0);
}

app(SyncCatalogOutboxService::class)->inventoryTransferUpdated($transfer);

echo "Backfill listo: encolado inventory_transfer.updated para traslado id={$transfer->id} (status actual: {$transfer->status}).\n";
echo "El worker local lo va a empujar a la nube en el proximo ciclo (<= {$transfer->id} segundos).\n";
