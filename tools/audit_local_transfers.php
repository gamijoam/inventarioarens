<?php
require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Local DB: inventory_arens ===\n";
echo "host=" . config('database.connections.pgsql.host') . ":" . config('database.connections.pgsql.port') . "\n\n";

echo "--- Tenants (id, slug, name, domain) ---\n";
$tenants = DB::table('tenants')->orderBy('id')->get(['id', 'slug', 'name', 'domain']);
foreach ($tenants as $t) {
    echo sprintf("  id=%d slug=%s name=%s domain=%s\n", $t->id, $t->slug, $t->name, $t->domain ?? '(null)');
}
echo "\n";

echo "--- Inventory transfers (per tenant) ---\n";
$rows = DB::table('inventory_transfers')
    ->select('tenant_id', DB::raw('count(*) as n'), DB::raw("array_agg(distinct status) as statuses"))
    ->groupBy('tenant_id')
    ->get();
foreach ($rows as $r) {
    echo sprintf("  tenant_id=%d n=%d statuses=%s\n", $r->tenant_id, $r->n, $r->statuses);
}
echo "\n";

echo "--- Last 5 transfers (id, tenant, document, status) ---\n";
$rows = DB::table('inventory_transfers')
    ->orderByDesc('id')
    ->limit(5)
    ->get(['id', 'tenant_id', 'document_number', 'status', 'created_at']);
foreach ($rows as $r) {
    echo sprintf("  id=%d tenant_id=%d doc=%s status=%s created_at=%s\n",
        $r->id, $r->tenant_id, $r->document_number, $r->status, $r->created_at);
}
echo "\n";

echo "--- sync_outbox (recent, for inventory_transfer) ---\n";
$rows = DB::table('sync_outbox')
    ->where('event_type', 'like', 'inventory_transfer%')
    ->orderByDesc('id')
    ->limit(10)
    ->get(['id', 'tenant_id', 'event_type', 'status', 'target_node_id', 'created_at', 'last_error']);
foreach ($rows as $r) {
    echo sprintf("  id=%d tenant=%d event=%s status=%s target=%s err=%s\n",
        $r->id, $r->tenant_id, $r->event_type, $r->status, $r->target_node_id, $r->last_error ?? '-');
}
echo "\n";

echo "--- sync_inbox (recent) ---\n";
$rows = DB::table('sync_inbox')
    ->orderByDesc('id')
    ->limit(5)
    ->get(['id', 'event_type', 'status', 'created_at', 'last_error']);
foreach ($rows as $r) {
    echo sprintf("  id=%d event=%s status=%s err=%s\n",
        $r->id, $r->event_type, $r->status, $r->last_error ?? '-');
}
