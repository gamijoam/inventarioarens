<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$cols = \DB::select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name='stock_balances' ORDER BY ordinal_position");
foreach ($cols as $c) { echo "{$c->column_name} ({$c->data_type})\n"; }

echo "\n--- product_units columns ---\n";
$cols2 = \DB::select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name='product_units' ORDER BY ordinal_position");
foreach ($cols2 as $c) { echo "{$c->column_name} ({$c->data_type})\n"; }

echo "\n--- warehouses columns ---\n";
$cols3 = \DB::select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name='warehouses' ORDER BY ordinal_position LIMIT 15");
foreach ($cols3 as $c) { echo "{$c->column_name} ({$c->data_type})\n"; }
