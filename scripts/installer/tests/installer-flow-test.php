<?php

declare(strict_types=1);

putenv('BALANZAPRO_INSTALLER_HELPER_LIBRARY_ONLY=1');
require dirname(__DIR__).'/install-helper.php';

function flowCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function runMigrationFixture(string $repoRoot, string $databasePath): void
{
    $environment = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $databasePath,
        'DB_URL' => '',
        'DB_FOREIGN_KEYS' => 'true',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'LOG_CHANNEL' => 'stderr',
    ]);
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $repoRoot.DIRECTORY_SEPARATOR.'artisan', 'migrate:fresh', '--force', '--no-interaction'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $repoRoot,
        $environment
    );
    flowCheck(is_resource($process), 'No se pudo iniciar las migraciones del fixture.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    flowCheck($exit === 0, 'Las migraciones del fixture fallaron: '.$output);
}

function buildFlowZip(string $root, string $databasePath): string
{
    $companyConfig = [
        'tenant_slug' => 'installer-flow-company',
        'company_name' => 'Empresa instalador',
        'proxy_port' => 8787,
        'backend_port' => 8788,
        'offline_mode' => true,
    ];
    $configPath = $root.DIRECTORY_SEPARATOR.'config.json';
    file_put_contents($configPath, json_encode($companyConfig, JSON_UNESCAPED_SLASHES));

    $imagePath = $root.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'products';
    ensureDirectory($imagePath);
    file_put_contents($imagePath.DIRECTORY_SEPARATOR.'sample.jpg', 'fixture-image-bytes');

    $payload = [
        'database.sqlite' => $databasePath,
        'config.json' => $configPath,
        'images/products/sample.jpg' => $imagePath.DIRECTORY_SEPARATOR.'sample.jpg',
    ];
    $manifestFiles = [];
    foreach ($payload as $name => $path) {
        $manifestFiles[$name] = ['size_bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
    }
    $manifestPath = $root.DIRECTORY_SEPARATOR.'manifest.json';
    file_put_contents($manifestPath, json_encode([
        'format' => 'balanzapro-offline-package',
        'format_version' => 1,
        'tenant_slug' => 'installer-flow-company',
        'company_name' => 'Empresa instalador',
        'database_integrity' => 'ok',
        'files' => $manifestFiles,
    ], JSON_UNESCAPED_SLASHES));

    $archivePath = $root.DIRECTORY_SEPARATOR.'BalanzaPro-datos-installer-flow-company.zip';
    $zip = new ZipArchive;
    flowCheck($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'No se pudo crear el paquete.');
    foreach ($payload as $name => $path) {
        $zip->addFile($path, $name);
    }
    $zip->addFile($manifestPath, 'manifest.json');
    flowCheck($zip->close(), 'No se pudo finalizar el paquete.');

    return $archivePath;
}

function rolePermissionCount(string $databasePath): int
{
    $pdo = new PDO('sqlite:'.$databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $count = (int) $pdo->query(
        "SELECT COUNT(*) FROM role_has_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.guard_name = 'web'"
    )->fetchColumn();
    $pdo = null;

    return $count;
}

function permissionCacheCount(string $databasePath): int
{
    $pdo = new PDO('sqlite:'.$databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $count = (int) $pdo->query("SELECT COUNT(*) FROM cache WHERE lower(key) LIKE '%spatie.permission.cache%'")->fetchColumn();
    $pdo = null;

    return $count;
}

function insertStalePermissionCache(string $databasePath): void
{
    $pdo = new PDO('sqlite:'.$databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $statement = $pdo->prepare('INSERT OR REPLACE INTO cache (key, value, expiration) VALUES (?, ?, ?)');
    $statement->execute(['laravel-cache-spatie.permission.cache', 'stale permission catalog', time() + 3600]);
    $pdo = null;
}

function countRows(string $databasePath): int
{
    $pdo = new PDO('sqlite:'.$databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $count = (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE slug = 'installer-flow-company'")->fetchColumn();
    $pdo = null;

    return $count;
}

$repoRoot = dirname(__DIR__, 3);
$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'balanzapro-installer-flow-'.bin2hex(random_bytes(5));
ensureDirectory($root);

try {
    $sourceDatabase = $root.DIRECTORY_SEPARATOR.'company.sqlite';
    runMigrationFixture($repoRoot, $sourceDatabase);
    $pdo = new PDO('sqlite:'.$sourceDatabase, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $insert = $pdo->prepare('INSERT INTO tenants (name, slug, created_at, updated_at) VALUES (?, ?, ?, ?)');
    $now = date('Y-m-d H:i:s');
    $insert->execute(['Empresa instalador', 'installer-flow-company', $now, $now]);
    $staleCache = $pdo->prepare('INSERT INTO cache (key, value, expiration) VALUES (?, ?, ?)');
    $staleCache->execute(['laravel-cache-spatie.permission.cache', 'stale permission catalog', time() + 3600]);
    $pdo->exec('CREATE TABLE installer_flow_probe (value TEXT NOT NULL)');
    $pdo->exec("INSERT INTO installer_flow_probe (value) VALUES ('preservar')");
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $pdo->exec('PRAGMA journal_mode = DELETE');
    $insert = null;
    $pdo = null;
    flowCheck(countRows($sourceDatabase) === 1, 'El fixture no guardó el tenant antes de crear el ZIP.');

    $packageRoot = $root.DIRECTORY_SEPARATOR.'package';
    ensureDirectory($packageRoot);
    $archive = buildFlowZip($packageRoot, $sourceDatabase);
    $dataRoot = $root.DIRECTORY_SEPARATOR.'ProgramData'.DIRECTORY_SEPARATOR.'BalanzaPro';
    $phpIni = php_ini_loaded_file() ?: '';
    freshInstall($dataRoot, $archive, $repoRoot, PHP_BINARY, $phpIni);

    $database = $dataRoot.DIRECTORY_SEPARATOR.'database.sqlite';
    flowCheck(countRows($database) === 1, 'La instalación nueva no conservó el tenant.');
    flowCheck(is_file($dataRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'products'.DIRECTORY_SEPARATOR.'sample.jpg'), 'No se copiaron las imágenes.');
    flowCheck(is_file($dataRoot.DIRECTORY_SEPARATOR.'app.key'), 'No se creó la clave privada local.');
    flowCheck(rolePermissionCount($database) > 0, 'La instalación nueva no restauró los permisos predeterminados.');
    flowCheck(permissionCacheCount($database) === 0, 'La instalación nueva conservó una caché de permisos obsoleta.');

    try {
        freshInstall($dataRoot, $archive, $repoRoot, PHP_BINARY, $phpIni);
        throw new RuntimeException('La segunda instalación reemplazó una base ya existente.');
    } catch (RuntimeException $exception) {
        flowCheck(str_contains($exception->getMessage(), 'Ya existe una base local'), 'La instalación repetida falló de forma inesperada.');
    }

    $config = $dataRoot.DIRECTORY_SEPARATOR.'config.json';
    $legacyImages = $root.DIRECTORY_SEPARATOR.'legacy-images';
    ensureDirectory($legacyImages);
    $legacyEnvironment = $root.DIRECTORY_SEPARATOR.'legacy.env';
    file_put_contents($legacyEnvironment, 'APP_KEY='.trim((string) file_get_contents($dataRoot.DIRECTORY_SEPARATOR.'app.key')).PHP_EOL);

    $legacyDatabase = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $legacyDatabase->exec('DELETE FROM role_has_permissions');
    $legacyDatabase = null;
    insertStalePermissionCache($database);
    flowCheck(rolePermissionCount($database) === 0, 'El fixture no simuló el paquete antiguo sin permisos.');
    flowCheck(permissionCacheCount($database) === 1, 'El fixture no simuló una caché de permisos obsoleta.');

    prepareUpgrade($dataRoot, $database, $config, $legacyEnvironment, $legacyImages, $repoRoot, PHP_BINARY, $phpIni);
    flowCheck(countRows($database) === 1, 'Preparar una actualización cambió la base activa.');
    flowCheck(rolePermissionCount($database) === 0, 'La reparación cambió la base activa antes de la activación.');
    flowCheck(permissionCacheCount($database) === 1, 'Preparar la actualización alteró la base activa.');
    activateUpgrade($dataRoot);
    flowCheck(rolePermissionCount($database) > 0, 'La actualización no reparó los permisos del paquete antiguo.');
    flowCheck(permissionCacheCount($database) === 0, 'La actualización conservó una caché de permisos obsoleta.');
    commitUpgrade($dataRoot);

    prepareUpgrade($dataRoot, $database, $config, $legacyEnvironment, $legacyImages, $repoRoot, PHP_BINARY, $phpIni);
    activateUpgrade($dataRoot);

    $candidatePdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $candidatePdo->exec("INSERT INTO installer_flow_probe (value) VALUES ('candidato')");
    $candidatePdo = null;
    rollbackUpgrade($dataRoot);

    flowCheck(countRows($database) === 1, 'La recuperación no restauró la base anterior.');
    $restored = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $probeCount = (int) $restored->query('SELECT COUNT(*) FROM installer_flow_probe')->fetchColumn();
    $restored = null;
    flowCheck($probeCount === 1, 'La recuperación no conservó los datos previos a la actualización.');
    flowCheck(rolePermissionCount($database) > 0, 'La recuperación perdió los permisos reparados.');

    $failedCandidates = glob($dataRoot.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.'database-failed-update-*.sqlite') ?: [];
    flowCheck($failedCandidates !== [], 'No se guardó una copia del candidato fallido.');

    fwrite(STDOUT, "Flujo de instalación, protección de primera instalación y recuperación: OK\n");
} finally {
    removeTree($root);
}
