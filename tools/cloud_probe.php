<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

$tenant = \App\Modules\Tenancy\Models\Tenant::query()->first();
$token = config('services.sync.token');
$cloudUrl = rtrim(config('services.sync.cloud_url'), '/');

echo "Cloud URL: {$cloudUrl}\n";
echo "Tenant slug: {$tenant->slug}\n\n";

function probe(string $label, $response): void {
    echo "=== {$label} ===\n";
    if ($response === null) {
        echo "  No response\n\n";
        return;
    }
    echo "  HTTP: " . $response->status() . "\n";
    $body = $response->body();
    echo "  Body: " . substr($body, 0, 800) . "\n\n";
}

// 1. Sync status
$r = Http::withToken($token)->withHeaders(['X-Tenant' => $tenant->slug])
    ->timeout(20)->acceptJson()->get("{$cloudUrl}/sync/status");
probe('TEST 1: GET /sync/status', $r);

// 2. Pull events
$r = Http::withToken($token)->withHeaders(['X-Tenant' => $tenant->slug])
    ->timeout(20)->acceptJson()->get("{$cloudUrl}/sync/events/pull", [
        'node_code' => 'LOCAL-DEMO-VALENCIA-GAMIJOAM',
        'limit' => 5,
    ]);
probe('TEST 2: GET /sync/events/pull (5)', $r);

// 3. Push a fake inventory_transfer event
$probeUuid = 'test-' . bin2hex(random_bytes(8));
$payload = [
    'origin_node_code' => 'LOCAL-DEMO-VALENCIA-GAMIJOAM',
    'events' => [[
        'event_uuid' => $probeUuid,
        'event_type' => 'inventory_transfer.created',
        'aggregate_type' => 'inventory_transfer',
        'aggregate_id' => 999999,
        'payload' => [
            'id' => 999999,
            'document_number' => 'TRF-PROBE-' . strtoupper(substr($probeUuid, 5, 6)),
            'guide_number' => 'GUIA-PROBE',
            'type' => 'internal',
            'validation_mode' => 'simple',
            'status' => 'completed',
            'resolution_status' => 'unresolved',
            'from_warehouse_code' => 'VAL-01',
            'to_warehouse_code' => 'VAL-02',
            'reason' => 'PROBE DE AUDITORIA SYNC',
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
$r = Http::withToken($token)->withHeaders(['X-Tenant' => $tenant->slug])
    ->timeout(20)->acceptJson()->post("{$cloudUrl}/sync/events/push", $payload);
probe("TEST 3: POST /sync/events/push (probe uuid={$probeUuid})", $r);

// 4. Try public endpoint
$r = Http::timeout(20)->acceptJson()->get("https://app.miinventariofacil.com/api/v1/inventory-transfers");
probe('TEST 4: GET /api/v1/inventory-transfers (no auth)', $r);

// 5. Try other cloud endpoints to discover what's there
foreach (['/sync/nodes', '/sync/health', '/health', '/api/health', '/v1/health', '/api/v1/health'] as $ep) {
    $r = Http::timeout(10)->acceptJson()->get($cloudUrl . $ep);
    if ($r->status() !== 404) {
        echo "=== TEST 5: GET {$ep} ===\n";
        echo "  HTTP: " . $r->status() . "\n";
        echo "  Body: " . substr($r->body(), 0, 200) . "\n\n";
    }
}
