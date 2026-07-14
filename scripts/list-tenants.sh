#!/bin/bash
cd /opt/inventarioarens-cloud
php artisan tinker <<'EOF'
$tenants = \App\Modules\Tenancy\Models\Tenant::query()->orderBy('id')->get(['id', 'name', 'slug', 'status', 'plan'])->toArray();
echo json_encode(['total' => count($tenants), 'tenants' => $tenants], JSON_PRETTY_PRINT) . PHP_EOL;
EOF
