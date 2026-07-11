<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenant = \App\Modules\Tenancy\Models\Tenant::query()->first();
echo "Tenant: {$tenant->slug} (id={$tenant->id})\n\n";

echo "--- TODOS los inventory_transfer.* en outbox ---\n";
$rows = \DB::table('sync_outbox')->where('tenant_id', $tenant->id)
    ->whereIn('event_type', ['inventory_transfer.created', 'inventory_transfer.updated'])
    ->orderBy('id')->get();
foreach ($rows as $r) {
    $payload = json_decode($r->payload, true);
    echo sprintf("#%-3d  %-28s  aggr=%-3s  target_node=%-5s  scope=%-7s  status=%-10s  attempts=%d  occ=%s  upd=%s  err=%s\n",
        $r->id, $r->event_type, $r->aggregate_id, $r->target_node_id ?? 'null', $r->target_scope, $r->status, $r->attempts, $r->occurred_at, $r->updated_at, substr($r->last_error ?? '', 0, 60));
    echo "       doc={$payload['document_number']}  status_in_payload={$payload['status']}  key={$r->idempotency_key}\n";
}

echo "\n--- NODOS ACTIVOS ---\n";
$nodes = \DB::table('sync_nodes')->where('tenant_id', $tenant->id)->get(['id','code','type','status','last_seen_at']);
foreach ($nodes as $n) {
    echo sprintf("  id=%d  code=%s  type=%s  status=%s  last_seen=%s\n", $n->id, $n->code, $n->type, $n->status, $n->last_seen_at);
}

echo "\n--- SYNC_STATES (last 10) ---\n";
$states = \DB::table('sync_states')->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(10)->get();
foreach ($states as $s) {
    echo sprintf("  direction=%s  node_id=%d  last_event_id=%s  err=%s\n",
        $s->direction, $s->node_id, $s->last_event_id, substr($s->last_error ?? '', 0, 80));
}
