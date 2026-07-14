<?php
// Limpia el cache de Laravel, incluyendo rate limit.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Illuminate\Support\Facades\Cache::clear();
Illuminate\Support\Facades\Artisan::call('cache:clear');
echo "Cache y rate limit limpiados\n";
