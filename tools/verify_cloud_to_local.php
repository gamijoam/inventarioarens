<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenant = \App\Modules\Tenancy\Models\Tenant::query()->first();

echo "=== Local sync_inbox (latest 10) ===\n";
$rows = \DB::table('sync_inbox')->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(10)->get(['id','event_type','aggregate_id','status','last_error','payload','updated_at']);
foreach ($rows as $r) {
    $p = json_decode($r->payload, true);
    $doc = $p['document_number'] ?? '-';
    $status = $p['status'] ?? '-';
    $items = isset($p['items']) ? count($p['items']) : 0;
    echo sprintf("  #%-3d  %-30s  aggr=%-6s  status=%-10s  doc=%-15s  trans_status=%-15s  items=%d  err=%s\n",
        $r->id, $r->event_type, $r->aggregate_id ?? 'null', $r->status, $doc, $status, $items, substr($r->last_error ?? '', 0, 60));
}

echo "\n=== Local inventory_transfers (post pull) ===\n";
$rows = \DB::table('inventory_transfers')->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(10)->get(['id','document_number','status','created_at','updated_at']);
foreach ($rows as $r) {
    echo sprintf("  #%-3d  %s  status=%-25s  created=%s  updated=%s\n",
        $r->id, $r->document_number, $r->status, $r->created_at, $r->updated_at);
}

echo "\n=== Local inventory_transfer_items for those transfers ===\n";
$rows = \DB::table('inventory_transfer_items')->whereIn('inventory_transfer_id', \DB::table('inventory_transfers')->where('tenant_id', $tenant->id)->pluck('id')->all())->get();
foreach ($rows as $r) {
    echo sprintf("  item_id=%-3d  transfer_id=%-3d  product_id=%-3d  qty=%s  prep=%s  recv=%s\n",
        $r->id, $r->inventory_transfer_id, $r->product_id, $r->quantity, $r->prepared_quantity ?? '-', $r->received_quantity ?? '-');
}

echo "\n=== Local sync_inbox count por event_type (post pull) ===\n";
$localByType = \DB::table('sync_inbox')->where('tenant_id', $tenant->id)
    ->groupBy('event_type')->selectRaw('event_type, COUNT(*) as c')->orderByDesc('c')->get();
foreach ($localByType as $r) {
    echo "  {$r->event_type} = {$r->c}\n";
}
