<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$tenant = \App\Modules\Tenancy\Models\Tenant::query()->first();
$token = config('services.sync.token');
$cloudUrl = rtrim(config('services.sync.cloud_url'), '/');

echo "=== ANTES: local sync_inbox count por event_type ===\n";
$localByType = \DB::table('sync_inbox')->where('tenant_id', $tenant->id)
    ->groupBy('event_type')->selectRaw('event_type, COUNT(*) as c')->orderByDesc('c')->get();
foreach ($localByType as $r) {
    echo "  {$r->event_type} = {$r->c}\n";
}
echo "Total: " . $localByType->sum(fn ($r) => $r->c) . "\n\n";

echo "=== Cloud nodes (discover local node id en la nube) ===\n";
$r = Http::withToken($token)->withHeaders(['X-Tenant' => $tenant->slug])
    ->timeout(20)->acceptJson()->get("{$cloudUrl}/sync/status", ['node_code' => 'LOCAL-DEMO-VALENCIA-GAMIJOAM']);
$status = $r->json('data');
$localNodeInCloud = $status['node'] ?? null;
echo "Local node in cloud: " . json_encode($localNodeInCloud) . "\n\n";

// 1. Push inventory_transfer.created from a DIFFERENT origin (simulating web)
$probeUuid = Str::uuid()->toString();
$payload = [
    'origin_node_code' => 'WEB-SIMULATED',  // ← origin distinto al local
    'events' => [[
        'event_uuid' => $probeUuid,
        'event_type' => 'inventory_transfer.created',
        'aggregate_type' => 'inventory_transfer',
        'aggregate_id' => 777555,
        'payload' => [
            'id' => 777555,
            'document_number' => 'TRF-WEB-' . strtoupper(substr($probeUuid, 0, 4)),
            'guide_number' => 'GUIA-WEB-PROBE',
            'type' => 'internal',
            'validation_mode' => 'logistics',
            'status' => 'requested',
            'resolution_status' => 'unresolved',
            'from_warehouse_code' => 'VAL-01',
            'to_warehouse_code' => 'VAL-02',
            'reason' => 'TRASLADO DESDE LA WEB',
            'items' => [[
                'id' => 1,
                'sku' => 'AUD-BT-VAL',
                'quantity' => '3.0000',
                'requested_quantity' => '3.0000',
                'prepared_quantity' => '0.0000',
                'received_quantity' => '0.0000',
                'difference_quantity' => '0.0000',
            ]],
        ],
        'occurred_at' => now()->toISOString(),
    ]],
];
echo "=== TEST: Push inventory_transfer.created (origin=WEB-SIMULATED, uuid={$probeUuid}) ===\n";
$r = Http::withToken($token)->withHeaders(['X-Tenant' => $tenant->slug])
    ->timeout(20)->acceptJson()->post("{$cloudUrl}/sync/events/push", $payload);
echo "HTTP: " . $r->status() . "\n";
echo "Body: " . substr($r->body(), 0, 500) . "\n\n";

echo "=== DESPUES: cloud outbox inventory_transfer.* (top 5) ===\n";
$r = Http::withToken($token)->withHeaders(['X-Tenant' => $tenant->slug])
    ->timeout(20)->acceptJson()->get("{$cloudUrl}/sync/status");
$status = $r->json('data');
foreach (array_slice($status['latest_events']['outbox'], 0, 5) as $e) {
    if (str_contains($e['event_type'], 'inventory_transfer')) {
        echo sprintf("  #%d  %-30s  aggr=%-6s  status=%-10s  orig=%-3s  target=%-3s  occ=%s\n",
            $e['id'], $e['event_type'], $e['aggregate_id'] ?? 'null', $e['status'], $e['origin_node_id'] ?? 'null', $e['target_node_id'] ?? 'null', $e['occurred_at']);
    }
}

echo "\n=== Ahora ejecuto worker local para hacer pull ===\n";
echo "(Ejecuta: php artisan sync:run demo-valencia --node=LOCAL-DEMO-VALENCIA-GAMIJOAM --cloud-url=... --limit=20)\n";
