<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenant = \App\Modules\Tenancy\Models\Tenant::query()->first();
echo "Tenant activo: {$tenant->slug} (id={$tenant->id})\n";

$fromWh = \App\Modules\Warehouses\Models\Warehouse::query()->where('tenant_id', $tenant->id)->orderBy('id')->first();
$toWh = \App\Modules\Warehouses\Models\Warehouse::query()->where('tenant_id', $tenant->id)->where('id', '!=', $fromWh->id)->orderBy('id')->first();
$product = \App\Modules\Products\Models\Product::query()->where('tenant_id', $tenant->id)->where('tracking_type', 'quantity')->orderBy('id')->first();
$user = \App\Models\User::query()->first();

if (!$fromWh || !$toWh || !$product || !$user) {
    echo "ERROR: faltan datos base (wh={$fromWh->id}/{$toWh->id}, product={$product->id}, user=" . ($user?->id ?? 'null') . ")\n";
    exit;
}

echo "From WH: {$fromWh->code} (id={$fromWh->id})\n";
echo "To WH:   {$toWh->code} (id={$toWh->id})\n";
echo "Product: {$product->sku} (id={$product->id})\n";
echo "User: {$user->email}\n\n";

// Check stock
$stockRow = \DB::table('stock_balances')->where('tenant_id', $tenant->id)
    ->where('warehouse_id', $fromWh->id)
    ->where('product_id', $product->id)
    ->first();
$stock = $stockRow->quantity_available ?? 0;
echo "Stock actual en {$fromWh->code} para {$product->sku}: {$stock}\n\n";

if ((float) $stock < 5) {
    echo "No hay stock suficiente. Creo movimiento de entrada para tener stock.\n";
    \DB::table('stock_movements')->insert([
        'tenant_id' => $tenant->id,
        'warehouse_id' => $fromWh->id,
        'product_id' => $product->id,
        'type' => 'in',
        'quantity' => 100,
        'unit_cost' => 1.00,
        'reason' => 'Setup para test sync audit',
        'reference_type' => 'manual',
        'reference_id' => null,
        'created_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    \DB::table('stock_balances')->updateOrInsert(
        ['tenant_id' => $tenant->id, 'warehouse_id' => $fromWh->id, 'product_id' => $product->id],
        ['quantity_available' => 100, 'quantity_reserved' => 0, 'quantity_damaged' => 0, 'updated_at' => now()]
    );
    echo "Stock creado.\n\n";
}

// Grant role via direct table insert (avoid spatie team issue)
$user->tenants()->syncWithoutDetaching([$tenant->id => ['status' => 'active']]);
$existing = \DB::table('model_has_roles')->where('model_id', $user->id)->where('model_type', \App\Models\User::class)->where('role_id', 1)->where('tenant_id', $tenant->id)->exists();
if (!$existing) {
    \DB::table('model_has_roles')->insert([
        'role_id' => 1,
        'model_type' => \App\Models\User::class,
        'model_id' => $user->id,
        'tenant_id' => $tenant->id,
    ]);
}

// Create via HTTP-like service
echo "=== CREANDO TRASLADO NUEVO (TEST SYNC AUDIT) ===\n";
$maxSeq = \DB::table('inventory_transfers')->where('tenant_id', $tenant->id)->max('sequence') ?? 0;
$newSeq = $maxSeq + 1;

$transferService = app(\App\Modules\InventoryTransfers\Services\InventoryTransferService::class);
app(\App\Support\Tenancy\TenantManager::class)->set($tenant);
try {
    $newTransfer = $transferService->create($user, [
        'validation_mode' => 'logistics',
        'from_warehouse_id' => $fromWh->id,
        'to_warehouse_id' => $toWh->id,
        'type' => 'internal',
        'reason' => 'AUDITORIA SYNC - ' . now()->format('Y-m-d H:i:s'),
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2],
        ],
    ]);
    echo "Traslado creado: id={$newTransfer->id}, doc={$newTransfer->document_number}, status={$newTransfer->status}\n";
    echo "validation_mode={$newTransfer->validation_mode}\n\n";
} catch (\Throwable $e) {
    echo "ERROR creando traslado: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit;
}

echo "=== OUTBOX POST-CREATE ===\n";
$rows = \DB::table('sync_outbox')->where('tenant_id', $tenant->id)
    ->whereIn('event_type', ['inventory_transfer.created', 'inventory_transfer.updated'])
    ->where('aggregate_id', $newTransfer->id)
    ->orderBy('id')->get(['id','event_type','aggregate_id','status','payload','idempotency_key']);
foreach ($rows as $r) {
    $payload = json_decode($r->payload, true);
    echo "  #{$r->id}  {$r->event_type}  aggr={$r->aggregate_id}  status={$r->status}  doc={$payload['document_number']}  transfer_status_in_payload={$payload['status']}\n";
}

echo "\nListo. El ID del nuevo traslado es: {$newTransfer->id}\n";
