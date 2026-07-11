<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

$tenant = \App\Modules\Tenancy\Models\Tenant::query()->first();
$token = config('services.sync.token');
$cloudUrl = rtrim(config('services.sync.cloud_url'), '/');

// 1. Push a VALID inventory_transfer event with proper UUID
$probeUuid = \Illuminate\Support\Str::uuid()->toString();
$payload = [
    'origin_node_code' => 'LOCAL-DEMO-VALENCIA-GAMIJOAM',
    'events' => [[
        'event_uuid' => $probeUuid,
        'event_type' => 'inventory_transfer.created',
        'aggregate_type' => 'inventory_transfer',
        'aggregate_id' => 999888,
        'payload' => [
            'id' => 999888,
            'document_number' => 'TRF-PROBE-' . strtoupper(substr($probeUuid, 0, 4)),
            'guide_number' => 'GUIA-PROBE',
            'type' => 'internal',
            'validation_mode' => 'simple',
            'status' => 'completed',
            'resolution_status' => 'unresolved',
            'from_warehouse_code' => 'VAL-01',
            'to_warehouse_code' => 'VAL-02',
            'reason' => 'PROBE AUDITORIA SYNC',
            'items' => [[
                'id' => 1,
                'sku' => 'AUD-BT-VAL',
                'quantity' => '1.0000',
                'requested_quantity' => '1.0000',
                'prepared_quantity' => '1.0000',
                'received_quantity' => '1.0000',
                'difference_quantity' => '0.0000',
            ]],
        ],
        'occurred_at' => now()->toISOString(),
    ]],
];
echo "=== TEST: Push inventory_transfer.created (valid UUID={$probeUuid}) ===\n";
$r = Http::withToken($token)->withHeaders(['X-Tenant' => $tenant->slug])
    ->timeout(20)->acceptJson()->post("{$cloudUrl}/sync/events/push", $payload);
echo "HTTP: " . $r->status() . "\n";
echo "Body: " . substr($r->body(), 0, 1500) . "\n\n";

// 2. Get full status now
echo "=== TEST: GET /sync/status (post-push) ===\n";
$r = Http::withToken($token)->withHeaders(['X-Tenant' => $tenant->slug])
    ->timeout(20)->acceptJson()->get("{$cloudUrl}/sync/status");
$status = $r->json('data');
echo "outbox.pending={$status['outbox']['pending']}  processed={$status['outbox']['processed']}  failed={$status['outbox']['failed']}\n";
echo "inbox.received={$status['inbox']['received']}  applied={$status['inbox']['applied']}  failed={$status['inbox']['failed']}\n\n";

echo "=== latest_events.outbox (10) ===\n";
foreach ($status['latest_events']['outbox'] as $e) {
    echo sprintf("  #%d  %-32s  aggr=%-5s  status=%-10s  orig=%s  target=%s  occ=%s\n",
        $e['id'], $e['event_type'], $e['aggregate_id'] ?? 'null', $e['status'], $e['origin_node_id'] ?? 'null', $e['target_node_id'] ?? 'null', $e['occurred_at']);
}

echo "\n=== latest_events.inbox (10) ===\n";
foreach ($status['latest_events']['inbox'] as $e) {
    $err = substr($e['last_error'] ?? '', 0, 80);
    echo sprintf("  #%d  %-32s  aggr=%-5s  status=%-10s  err=%s\n",
        $e['id'], $e['event_type'], $e['aggregate_id'] ?? 'null', $e['status'], $err);
}

echo "\n=== TENANTS (discover via /sync/nodes) ===\n";
$r = Http::withToken($token)->withHeaders(['X-Tenant' => $tenant->slug])
    ->timeout(20)->acceptJson()->post("{$cloudUrl}/sync/nodes", [
        'code' => 'LOCAL-PROBE-DISCOVERY',
        'name' => 'Probe',
        'type' => 'local',
        'status' => 'active',
    ]);
echo "POST /sync/nodes: HTTP " . $r->status() . " body=" . substr($r->body(), 0, 200) . "\n";
