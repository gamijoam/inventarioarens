<?php
/**
 * Script de reset total de la base de datos.
 * 1. Hace backup del schema.
 * 2. DROP DATABASE.
 * 3. CREATE DATABASE.
 * 4. Aplica todas las migraciones.
 * 5. Seed con data demo.
 * 6. Crea un Platform Admin para testing.
 *
 * Uso:
 *   php scripts/reset-database.php
 *
 * PELIGRO: borra TODOS los datos. Solo para dev/staging.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "========================================\n";
echo "RESET TOTAL DE LA BASE DE DATOS\n";
echo "========================================\n\n";

echo "DB actual: " . config('database.connections.pgsql.database') . "\n";
echo "Host: " . config('database.connections.pgsql.host') . "\n";
echo "Port: " . config('database.connections.pgsql.port') . "\n\n";

$db = config('database.connections.pgsql.database');
$host = config('database.connections.pgsql.host');
$port = config('database.connections.pgsql.port');
$user = config('database.connections.pgsql.username');
$pass = config('database.connections.pgsql.password');

echo "=== Paso 1: Backup del schema (solo estructura) ===\n";
$backupFile = "storage/app/schema-backup-" . date('Y-m-d-His') . ".sql";
$backupFileFull = __DIR__ . "/../" . $backupFile;
$cmd = "pg_dump --schema-only --no-owner --no-privileges -h {$host} -p {$port} -U {$user} {$db} > {$backupFileFull} 2>&1";
exec($cmd, $output, $returnCode);
if ($returnCode === 0 && file_exists($backupFileFull) && filesize($backupFileFull) > 0) {
    echo "Backup guardado en: {$backupFile} (" . filesize($backupFileFull) . " bytes)\n";
} else {
    echo "Aviso: backup no se pudo crear. Continuando de todas formas.\n";
    echo "pg_dump output: " . implode("\n", $output) . "\n";
}

echo "\n=== Paso 2: DROP DATABASE {$db} ===\n";
$dropCmd = "dropdb -h {$host} -p {$port} -U {$user} --if-exists {$db} 2>&1";
exec($dropCmd, $dropOutput, $dropReturn);
if ($dropReturn === 0) {
    echo "Database {$db} dropeada.\n";
} else {
    echo "dropdb output: " . implode("\n", $dropOutput) . "\n";
    exit(1);
}

echo "\n=== Paso 3: CREATE DATABASE {$db} ===\n";
$createCmd = "createdb -h {$host} -p {$port} -U {$user} {$db} 2>&1";
exec($createCmd, $createOutput, $createReturn);
if ($createReturn === 0) {
    echo "Database {$db} creada.\n";
} else {
    echo "createdb output: " . implode("\n", $createOutput) . "\n";
    exit(1);
}

echo "\n=== Paso 4: Aplicar migraciones ===\n";
Artisan::call('migrate', ['--force' => true]);
echo Artisan::output();

echo "\n=== Paso 5: Seed con data demo ===\n";
Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
echo Artisan::output();
Artisan::call('db:seed', ['--class' => 'MultiCompanyLoginDemoSeeder', '--force' => true]);
echo Artisan::output();

echo "\n=== Paso 6: Crear Platform Admin ===\n";
Artisan::call('access:create-platform-admin', [
    'name' => 'Programador Test',
    'email' => 'tu@correo.test',
    '--password' => 'Programador123',
]);
echo Artisan::output();

echo "\n=== Resumen de tablas creadas ===\n";
$tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
echo "Total: " . count($tables) . " tablas\n";
echo "Primeras 10: " . implode(', ', array_slice(array_column($tables, 'tablename'), 0, 10)) . "\n";

echo "\n=== Paso 7: Listar tenants creados ===\n";
$tenants = DB::table('tenants')->orderBy('id')->get(['id', 'name', 'slug', 'status', 'plan']);
echo "Total: " . count($tenants) . " tenants\n";
foreach ($tenants as $t) {
    echo "  id={$t->id} name={$t->name} slug={$t->slug} status={$t->status} plan={$t->plan}\n";
}

echo "\n=== Paso 8: Listar usuarios ===\n";
$users = DB::table('users')->orderBy('id')->get(['id', 'name', 'email', 'is_platform_admin']);
echo "Total: " . count($users) . " users\n";
foreach ($users as $u) {
    $platform = $u->is_platform_admin ? ' (Platform Admin)' : '';
    echo "  id={$u->id} name={$u->name} email={$u->email}{$platform}\n";
}

echo "\n========================================\n";
echo "RESET COMPLETADO\n";
echo "========================================\n";
echo "\nCredenciales:\n";
echo "  Platform Admin: tu@correo.test / Programador123\n";
echo "  Login: POST /api/auth/platform-login (sin X-Tenant)\n";
echo "\n";
