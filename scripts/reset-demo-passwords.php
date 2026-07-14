<?php
/**
 * Resetea TODOS los passwords de usuarios demo a un unico valor.
 *
 * Esto evita confusion al loguearse: gabo y grupoprueba usan la misma
 * password. Por defecto es 'gabo1234' (8+ chars).
 *
 * Si necesitas otra password: php scripts/reset-demo-passwords.php mypass1234
 * (script auto-detecta el argumento y valida longitud).
 *
 * Equivalente Artisan: php artisan dev:reset-demo-passwords --password=...
 *
 * Ver AGENTS.md seccion "Demo users".
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Password unico. Acepta el primer argumento de la CLI.
$password = $argv[1] ?? 'gabo1234';

if (strlen($password) < 8) {
    fwrite(STDERR, "ERROR: password debe tener al menos 8 caracteres (recibido: " . strlen($password) . ")\n");
    exit(1);
}

$hashed = Illuminate\Support\Facades\Hash::make($password);

echo "=== Reseteando passwords a '{$password}' ===\n";
$count = 0;
foreach (App\Models\User::all() as $u) {
    $u->password = $hashed;
    $u->save();
    echo "  - {$u->email} ({$u->name})\n";
    $count++;
}
echo "\nReseteados: $count users\n";

echo "\n=== Verificacion ===\n";
foreach (App\Models\User::all() as $u) {
    $verified = Illuminate\Support\Facades\Hash::check($password, $u->password);
    echo "  - {$u->email}: ".($verified ? 'OK' : 'FALLO')."\n";
}