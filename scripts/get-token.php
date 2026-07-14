<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();

$t = \App\Modules\Auth\Models\AuthToken::with('user')->latest()->first();
if (!$t) {
    echo "NO TOKEN\n";
    exit;
}
echo "TOKEN: " . $t->token . "\n";
echo "USER: " . $t->user->email . "\n";