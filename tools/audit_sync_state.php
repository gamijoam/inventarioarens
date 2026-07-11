<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenant = \App\Modules\Tenancy\Models\Tenant::query()->first();
if (!$tenant) {
    echo "NO TENANT\n";
    exit;
}
echo "Tenant: {$tenant->slug} (id={$tenant->id})\n";

echo "---OUTBOX (ultimos 25)---\n";
$rows = \DB::table('sync_outbox')->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(25)->get(['id','event_type','aggregate_id','status','target_scope','attempts','last_error','updated_at']);
foreach ($rows as $r) {
    echo sprintf("#%-5d %-32s aggr=%-6s status=%-10s scope=%-7s attempts=%d  err=%s\n",
        $r->id, $r->event_type, $r->aggregate_id, $r->status, $r->target_scope, $r->attempts, substr($r->last_error ?? '', 0, 60));
}
echo "---COUNTS BY STATUS (outbox)---\n";
$counts = \DB::table('sync_outbox')->where('tenant_id', $tenant->id)->groupBy('status')->selectRaw('status, COUNT(*) as c')->get();
foreach ($counts as $c) { echo "  {$c->status} = {$c->c}\n"; }
echo "---PENDING BY TYPE (outbox)---\n";
$pendingByType = \DB::table('sync_outbox')->where('tenant_id', $tenant->id)->where('status','pending')->groupBy('event_type')->selectRaw('event_type, COUNT(*) as c')->orderByDesc('c')->get();
foreach ($pendingByType as $c) { echo "  {$c->event_type} = {$c->c}\n"; }
echo "---INBOX STATUS---\n";
$inboxCounts = \DB::table('sync_inbox')->where('tenant_id', $tenant->id)->groupBy('status')->selectRaw('status, COUNT(*) as c')->get();
foreach ($inboxCounts as $c) { echo "  {$c->status} = {$c->c}\n"; }
echo "---SYNC NODES---\n";
$nodes = \DB::table('sync_nodes')->where('tenant_id', $tenant->id)->get(['id','code','type','status','last_seen_at']);
foreach ($nodes as $n) {
    echo sprintf("  id=%d  code=%s  type=%s  status=%s  last_seen=%s\n", $n->id, $n->code, $n->type, $n->status, $n->last_seen_at);
}
echo "---INBOX RECENT (last 15 by id)---\n";
$inbox = \DB::table('sync_inbox')->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(15)->get(['id','event_type','event_uuid','status','last_error','updated_at']);
foreach ($inbox as $i) {
    echo sprintf("  #%-5d %-32s status=%-10s uuid=%s  err=%s\n",
        $i->id, $i->event_type, $i->status, substr($i->event_uuid, 0, 8), substr($i->last_error ?? '', 0, 50));
}
echo "---INVENTORY TRANSFERS LOCAL (last 5)---\n";
$transfers = \DB::table('inventory_transfers')->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(5)->get(['id','document_number','status','resolution_status','from_warehouse_id','to_warehouse_id','created_at']);
foreach ($transfers as $t) {
    echo sprintf("  #%-4d %s  status=%-25s res=%-10s created=%s\n",
        $t->id, $t->document_number, $t->status, $t->resolution_status ?? '-', $t->created_at);
}
