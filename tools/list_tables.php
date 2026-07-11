<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tables = \DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND (table_name LIKE '%stock%' OR table_name LIKE '%inventor%' OR table_name LIKE '%product%' OR table_name LIKE '%ware%') ORDER BY table_name");
foreach ($tables as $t) {
    echo $t->table_name . "\n";
}
