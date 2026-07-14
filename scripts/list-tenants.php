<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$tenants = \App\Modules\Tenancy\Models\Tenant::query()
    ->orderBy('id')
    ->get(['id', 'name', 'slug', 'status', 'plan'])
    ->toArray();

echo "Total tenants: " . count($tenants) . PHP_EOL;
echo json_encode($tenants, JSON_PRETTY_PRINT) . PHP_EOL;
