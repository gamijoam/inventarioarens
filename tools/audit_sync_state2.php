<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenant = \App\Modules\Tenancy\Models\Tenant::query()->first();
echo "Tenant: {$tenant->slug} (id={$tenant->id})\n\n";

echo "--- TODOS los inventory_transfer.* en outbox (este tenant) ---\n";
$rows = \DB::table('sync_outbox')->where('tenant_id', $tenant->id)
    ->whereIn('event_type', ['inventory_transfer.created', 'inventory_transfer.updated'])
    ->orderBy('id')->get(['id','event_uuid','event_type','aggregate_id','status','attempts','last_error','idempotency_key','payload','occurred_at','updated_at']);
foreach ($rows as $r) {
    $payload = json_decode($r->payload, true);
    $items = $payload['items'] ?? [];
    $itemCount = is_array($items) ? count($items) : 0;
    echo sprintf("#%-4d  %-30s  aggr=%-4s  status=%-10s  attempts=%d  items=%d  occ=%s  upd=%s\n",
        $r->id, $r->event_type, $r->aggregate_id, $r->status, $r->attempts, $itemCount, $r->occurred_at, $r->updated_at);
    echo "        status_in_payload={$payload['status']}  res={$payload['resolution_status']}  doc={$payload['document_number']}\n";
    if ($r->last_error) echo "        LAST_ERROR: " . substr($r->last_error, 0, 200) . "\n";
}

echo "\n--- TODOS los inventory_transfer.* en inbox (este tenant) ---\n";
$inbox = \DB::table('sync_inbox')->where('tenant_id', $tenant->id)
    ->whereIn('event_type', ['inventory_transfer.created', 'inventory_transfer.updated'])
    ->orderBy('id')->get(['id','event_uuid','event_type','aggregate_id','status','last_error','updated_at']);
echo "Total: " . count($inbox) . " eventos\n";
foreach ($inbox as $r) {
    echo sprintf("  #%-4d  %-30s  aggr=%-4s  status=%-10s  err=%s\n",
        $r->id, $r->event_type, $r->aggregate_id, $r->status, substr($r->last_error ?? '', 0, 80));
}

echo "\n--- TODOS los inventory_transfers en DB local ---\n";
$transfers = \DB::table('inventory_transfers')->where('tenant_id', $tenant->id)
    ->orderBy('id')->get(['id','document_number','status','resolution_status','from_warehouse_id','to_warehouse_id','created_at','updated_at']);
foreach ($transfers as $t) {
    $items = \DB::table('inventory_transfer_items')->where('inventory_transfer_id', $t->id)->count();
    echo sprintf("  #%-4d  %s  status=%-25s  res=%-10s  items=%d  from_wh=%s  to_wh=%s  created=%s\n",
        $t->id, $t->document_number, $t->status, $t->resolution_status ?? '-', $items, $t->from_warehouse_id, $t->to_warehouse_id, $t->created_at);
}

echo "\n--- ESTADO REAL DEL WORKER ---\n";
echo "Nodo local-GAMIJOAM: last_seen = " . \DB::table('sync_nodes')->where('code','LOCAL-DEMO-VALENCIA-GAMIJOAM')->value('last_seen_at') . "\n";
echo "Config cloud_url: " . config('services.sync.cloud_url') . "\n";
$statePush = \DB::table('sync_states')->where('direction','push')->orderByDesc('id')->first();
$statePull = \DB::table('sync_states')->where('direction','pull')->orderByDesc('id')->first();
echo "Last push state: event_uuid=" . ($statePush->last_event_uuid ?? 'none') . "  err=" . ($statePush->last_error ?? '') . "\n";
echo "Last pull state: event_uuid=" . ($statePull->last_event_uuid ?? 'none') . "  err=" . ($statePull->last_error ?? '') . "\n";
