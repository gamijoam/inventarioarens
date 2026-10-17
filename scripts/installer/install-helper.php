<?php

declare(strict_types=1);

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit($code);
}

function requireExtensions(): void
{
    foreach (['zip', 'pdo_sqlite'] as $extension) {
        if (! extension_loaded($extension)) {
            fail('Falta un componente de instalación requerido: '.$extension);
        }
    }
}

function ensureDirectory(string $path): void
{
    if (! is_dir($path) && ! mkdir($path, 0770, true) && ! is_dir($path)) {
        throw new RuntimeException('No se pudo crear una carpeta de datos.');
    }
}

function safeArchivePath(string $name): string
{
    if (
        $name === ''
        || str_contains($name, "\0")
        || str_contains($name, '\\')
        || str_starts_with($name, '/')
        || preg_match('/^[A-Za-z]:/', $name) === 1
    ) {
        throw new RuntimeException('El paquete contiene una ruta inválida.');
    }

    $parts = explode('/', $name);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new RuntimeException('El paquete contiene una ruta inválida.');
        }
    }

    return implode(DIRECTORY_SEPARATOR, $parts);
}

function hashZipEntry(ZipArchive $zip, int $index, string $target): array
{
    $input = $zip->getStream((string) $zip->getNameIndex($index));
    if (! is_resource($input)) {
        throw new RuntimeException('No se pudo leer un archivo del paquete.');
    }

    $output = fopen($target, 'xb');
    if (! is_resource($output)) {
        fclose($input);
        throw new RuntimeException('No se pudo preparar la importación de datos.');
    }

    $hash = hash_init('sha256');
    $size = 0;
    try {
        while (! feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('No se pudo leer un archivo del paquete.');
            }
            if ($chunk === '') {
                continue;
            }
            $size += strlen($chunk);
            hash_update($hash, $chunk);
            if (fwrite($output, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('No se pudo guardar un archivo del paquete.');
            }
        }
    } finally {
        fclose($input);
        fclose($output);
    }

    return ['size_bytes' => $size, 'sha256' => hash_final($hash)];
}

function validateSqlite(string $path, ?string $expectedSlug = null): void
{
    if (! is_file($path) || filesize($path) < 100) {
        throw new RuntimeException('La base de datos de la empresa no está completa.');
    }

    $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
        throw new RuntimeException('La base de datos no superó la comprobación de integridad.');
    }
    if ($pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_NUM) !== []) {
        throw new RuntimeException('La base de datos contiene referencias incompletas.');
    }

    $hasTenants = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'tenants'")->fetchColumn();
    if ($expectedSlug !== null && $hasTenants) {
        $columns = $pdo->query('PRAGMA table_info(tenants)')->fetchAll(PDO::FETCH_ASSOC);
        $hasSlug = count(array_filter($columns, static fn (array $column): bool => ($column['name'] ?? null) === 'slug')) > 0;
        if ($hasSlug) {
            $query = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE slug = :slug');
            $query->execute(['slug' => $expectedSlug]);
            if ((int) $query->fetchColumn() !== 1) {
                throw new RuntimeException('La empresa del paquete no coincide con su base de datos.');
            }
        }
    }

    $pdo = null;
}

function accessControlSummary(string $path): array
{
    $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = array_flip($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN));
    foreach (['tenants', 'permissions', 'roles', 'role_has_permissions'] as $table) {
        if (! isset($tables[$table])) {
            $pdo = null;

            return [
                'tenant_count' => 0,
                'permissions_count' => 0,
                'roles_count' => 0,
                'role_permission_assignments' => 0,
                'user_role_assignments' => 0,
            ];
        }
    }

    $summary = [
        'tenant_count' => (int) $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn(),
        'permissions_count' => (int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE guard_name = 'web'")->fetchColumn(),
        'roles_count' => (int) $pdo->query("SELECT COUNT(*) FROM roles WHERE guard_name = 'web'")->fetchColumn(),
        'role_permission_assignments' => (int) $pdo->query(
            "SELECT COUNT(*) FROM role_has_permissions rp
             INNER JOIN roles r ON r.id = rp.role_id
             WHERE r.guard_name = 'web'"
        )->fetchColumn(),
        'user_role_assignments' => isset($tables['model_has_roles'])
            ? (int) $pdo->query('SELECT COUNT(*) FROM model_has_roles')->fetchColumn()
            : 0,
    ];

    $pdo = null;

    return $summary;
}

function validateAccessControlDatabase(string $path, ?array $expected = null): void
{
    $summary = accessControlSummary($path);
    if (
        $summary['tenant_count'] < 1
        || $summary['permissions_count'] < 1
        || $summary['roles_count'] < 1
        || $summary['role_permission_assignments'] < 1
    ) {
        throw new RuntimeException('La base local no contiene los permisos completos para iniciar. El instalador intentó repararlos y no pudo.');
    }

    if ($expected === null) {
        return;
    }
    if (($expected['status'] ?? null) !== 'complete') {
        throw new RuntimeException('El paquete no confirma que sus permisos estén completos.');
    }

    foreach (['permissions_count', 'roles_count', 'role_permission_assignments', 'user_role_assignments'] as $key) {
        if (! array_key_exists($key, $expected) || (int) $expected[$key] !== $summary[$key]) {
            throw new RuntimeException('El catálogo o las asignaciones de permisos del paquete no coinciden con su manifiesto.');
        }
    }
}

function clearStalePermissionCache(string $databasePath): void
{
    $pdo = new PDO('sqlite:'.$databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $cacheTableExists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'cache'")->fetchColumn();
    if (! $cacheTableExists) {
        $pdo = null;

        return;
    }

    // Permission metadata is rebuilt from the migrated database when the local server starts.
    $statement = $pdo->prepare('DELETE FROM cache WHERE lower(key) LIKE :cache_key');
    $statement->execute(['cache_key' => '%spatie.permission.cache%']);

    $checkpoint = $pdo->query('PRAGMA wal_checkpoint(FULL)')->fetch(PDO::FETCH_NUM);
    if (is_array($checkpoint) && ((int) ($checkpoint[0] ?? 0) !== 0 || (int) ($checkpoint[1] ?? 0) !== (int) ($checkpoint[2] ?? 0))) {
        throw new RuntimeException('No se pudo actualizar la caché de permisos de la base local.');
    }

    $pdo = null;
}

function verifyAndExtractPackage(string $archivePath, string $destination): array
{
    if (! is_file($archivePath)) {
        throw new RuntimeException('No se encontró el ZIP de datos de la empresa junto al instalador.');
    }

    $zip = new ZipArchive;
    if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('El ZIP de datos no se pudo abrir.');
    }

    try {
        if ($zip->numFiles < 3 || $zip->numFiles > 50000) {
            throw new RuntimeException('El ZIP de datos tiene un contenido inesperado.');
        }

        $manifestText = $zip->getFromName('manifest.json');
        $manifest = is_string($manifestText) ? json_decode($manifestText, true) : null;
        if (
            ! is_array($manifest)
            || ($manifest['format'] ?? null) !== 'balanzapro-offline-package'
            || (int) ($manifest['format_version'] ?? 0) !== 1
            || ($manifest['database_integrity'] ?? null) !== 'ok'
            || ! is_array($manifest['files'] ?? null)
        ) {
            throw new RuntimeException('El ZIP no es un paquete oficial de datos de BalanzaPro.');
        }

        $slug = $manifest['tenant_slug'] ?? null;
        if (! is_string($slug) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/', $slug) !== 1) {
            throw new RuntimeException('El identificador de empresa del paquete no es válido.');
        }

        $configText = $zip->getFromName('config.json');
        $config = is_string($configText) ? json_decode($configText, true) : null;
        if (
            ! is_array($config)
            || ($config['tenant_slug'] ?? null) !== $slug
            || ! is_string($config['company_name'] ?? null)
        ) {
            throw new RuntimeException('La configuración no coincide con la empresa del paquete.');
        }

        $files = $manifest['files'];
        if (! isset($files['database.sqlite'], $files['config.json'])) {
            throw new RuntimeException('Al paquete le falta la base de datos o la configuración.');
        }

        ensureDirectory($destination);
        $seen = [];
        $manifestSeen = false;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (! is_string($name)) {
                throw new RuntimeException('El ZIP contiene un archivo inválido.');
            }

            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                $unixMode = ($attributes >> 16) & 0170000;
                if ($opsys === ZipArchive::OPSYS_UNIX && $unixMode === 0120000) {
                    throw new RuntimeException('El paquete contiene un enlace simbólico.');
                }
            }

            if ($name === 'manifest.json') {
                $manifestSeen = true;

                continue;
            }

            $relative = safeArchivePath($name);
            if (! isset($files[$name]) || isset($seen[$name]) || str_ends_with($name, '/')) {
                throw new RuntimeException('El ZIP contiene archivos que no están en su manifiesto.');
            }

            $expected = $files[$name];
            $stat = $zip->statIndex($index);
            if (
                ! is_array($expected)
                || ! is_array($stat)
                || (int) ($expected['size_bytes'] ?? -1) !== (int) ($stat['size'] ?? -2)
                || preg_match('/^[a-f0-9]{64}$/i', (string) ($expected['sha256'] ?? '')) !== 1
            ) {
                throw new RuntimeException('El tamaño de un archivo del paquete no coincide con el manifiesto.');
            }

            $target = $destination.DIRECTORY_SEPARATOR.$relative;
            ensureDirectory(dirname($target));
            $actual = hashZipEntry($zip, $index, $target);
            if (
                $actual['size_bytes'] !== (int) $expected['size_bytes']
                || ! hash_equals(strtolower((string) $expected['sha256']), $actual['sha256'])
            ) {
                throw new RuntimeException('La verificación SHA-256 del paquete falló.');
            }
            $seen[$name] = true;
        }

        if (! $manifestSeen || count($seen) !== count($files)) {
            throw new RuntimeException('El contenido del ZIP no coincide con su manifiesto.');
        }
        if (! isset($seen['database.sqlite'], $seen['config.json'])) {
            throw new RuntimeException('No se pudieron importar los archivos esenciales.');
        }

        validateSqlite($destination.DIRECTORY_SEPARATOR.'database.sqlite', $slug);
        if (isset($manifest['access_control'])) {
            validateAccessControlDatabase($destination.DIRECTORY_SEPARATOR.'database.sqlite', (array) $manifest['access_control']);
        }

        return ['manifest' => $manifest, 'config' => $config, 'root' => $destination];
    } finally {
        $zip->close();
    }
}

function copyTree(string $source, string $destination, bool $overwrite = false): void
{
    if (! is_dir($source)) {
        return;
    }
    ensureDirectory($destination);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1);
        $target = $destination.DIRECTORY_SEPARATOR.$relative;
        if ($item->isDir()) {
            ensureDirectory($target);

            continue;
        }
        ensureDirectory(dirname($target));
        if ($overwrite || ! is_file($target)) {
            if (! copy($item->getPathname(), $target)) {
                throw new RuntimeException('No se pudo conservar un archivo de la empresa.');
            }
        }
    }
}

function removeTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function appKey(string $dataRoot, ?string $legacyEnv = null): string
{
    $keyFile = $dataRoot.DIRECTORY_SEPARATOR.'app.key';
    if (is_file($keyFile)) {
        $key = trim((string) file_get_contents($keyFile));
        if ($key !== '') {
            return str_starts_with($key, 'base64:') ? $key : 'base64:'.$key;
        }
    }

    if ($legacyEnv !== null && is_file($legacyEnv)) {
        $contents = (string) file_get_contents($legacyEnv);
        if (preg_match('/^\s*APP_KEY\s*=\s*["\']?([^"\'\r\n]+)["\']?\s*$/m', $contents, $matches) === 1) {
            $key = trim($matches[1]);
            if ($key !== '' && ! str_contains($key, 'CHANGEME')) {
                ensureDirectory($dataRoot);
                file_put_contents($keyFile, $key, LOCK_EX);

                return str_starts_with($key, 'base64:') ? $key : 'base64:'.$key;
            }
        }
    }

    $key = 'base64:'.base64_encode(random_bytes(32));
    ensureDirectory($dataRoot);
    if (file_put_contents($keyFile, $key, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo crear la clave local de la empresa.');
    }

    return $key;
}

function ensureRuntimeStorage(string $dataRoot): string
{
    $storage = $dataRoot.DIRECTORY_SEPARATOR.'storage';
    foreach ([
        'app/public/products',
        'framework/cache/data',
        'framework/sessions',
        'framework/views',
        'logs',
    ] as $path) {
        ensureDirectory($storage.DIRECTORY_SEPARATOR.$path);
    }

    return $storage;
}

function runMigrations(string $phpBinary, string $phpIni, string $backendRoot, string $dataRoot, string $databasePath, string $key): void
{
    ensureRuntimeStorage($dataRoot);
    $environment = array_merge(getenv(), [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_KEY' => $key,
        'APP_URL' => 'http://localhost:8787',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $databasePath,
        'DB_FOREIGN_KEYS' => 'true',
        'DB_URL' => '',
        'LARAVEL_STORAGE_PATH' => $dataRoot.DIRECTORY_SEPARATOR.'storage',
        'INVENTARIO_APP_KEY_FILE' => $dataRoot.DIRECTORY_SEPARATOR.'app.key',
        'SESSION_DRIVER' => 'file',
        'CACHE_STORE' => 'file',
        'QUEUE_CONNECTION' => 'sync',
        'LOG_CHANNEL' => 'single',
        'LOG_LEVEL' => 'error',
        'PATH' => dirname($phpBinary).PATH_SEPARATOR.(getenv('PATH') ?: ''),
    ]);

    $command = [$phpBinary, '-c', $phpIni, $backendRoot.DIRECTORY_SEPARATOR.'artisan', 'migrate', '--force'];
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $backendRoot, $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('No se pudo iniciar la comprobación del esquema local.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $detail = trim((string) $stderr);
        if ($detail === '') {
            $detail = trim((string) $stdout);
        }
        throw new RuntimeException('La actualización del esquema no terminó correctamente.'.($detail !== '' ? "\n".$detail : ''));
    }
}

function runAccessControlSeeder(string $phpBinary, string $phpIni, string $backendRoot, string $dataRoot, string $databasePath, string $key): void
{
    ensureRuntimeStorage($dataRoot);
    $environment = array_merge(getenv(), [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_KEY' => $key,
        'APP_URL' => 'http://localhost:8787',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $databasePath,
        'DB_FOREIGN_KEYS' => 'true',
        'DB_URL' => '',
        'LARAVEL_STORAGE_PATH' => $dataRoot.DIRECTORY_SEPARATOR.'storage',
        'INVENTARIO_APP_KEY_FILE' => $dataRoot.DIRECTORY_SEPARATOR.'app.key',
        'SESSION_DRIVER' => 'file',
        'CACHE_STORE' => 'file',
        'QUEUE_CONNECTION' => 'sync',
        'LOG_CHANNEL' => 'single',
        'LOG_LEVEL' => 'error',
        'PATH' => dirname($phpBinary).PATH_SEPARATOR.(getenv('PATH') ?: ''),
    ]);

    $command = [
        $phpBinary,
        '-c',
        $phpIni,
        $backendRoot.DIRECTORY_SEPARATOR.'artisan',
        'db:seed',
        '--class=Database\Seeders\RolesAndPermissionsSeeder',
        '--force',
        '--no-interaction',
    ];
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $backendRoot, $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('No se pudo iniciar la reparación automática de permisos locales.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $detail = trim((string) $stderr);
        if ($detail === '') {
            $detail = trim((string) $stdout);
        }
        throw new RuntimeException('No se pudo restaurar automáticamente el catálogo de permisos.'.($detail !== '' ? "\n".$detail : ''));
    }
}

function repairAccessControlIfMissing(string $phpBinary, string $phpIni, string $backendRoot, string $dataRoot, string $databasePath, string $key): bool
{
    $summary = accessControlSummary($databasePath);
    if ($summary['tenant_count'] < 1 || $summary['role_permission_assignments'] > 0) {
        return false;
    }

    // Legacy snapshots may omit the global role-permission pivot. Seed only when it is empty,
    // preserving any non-empty role customizations from a valid package.
    runAccessControlSeeder($phpBinary, $phpIni, $backendRoot, $dataRoot, $databasePath, $key);

    return true;
}

function vacuumDatabase(string $source, string $destination): void
{
    if (! is_file($source)) {
        throw new RuntimeException('No se encontró la base de datos que se debe conservar.');
    }
    if (is_file($destination)) {
        unlink($destination);
    }

    $pdo = new PDO('sqlite:'.$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout = 30000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $checkpoint = $pdo->query('PRAGMA wal_checkpoint(FULL)')->fetch(PDO::FETCH_NUM);
    if (is_array($checkpoint) && ((int) ($checkpoint[0] ?? 0) !== 0 || (int) ($checkpoint[1] ?? 0) !== (int) ($checkpoint[2] ?? 0))) {
        throw new RuntimeException('La base de datos sigue ocupada; no se hará una actualización.');
    }
    validateSqlite($source);
    $pdo->exec('PRAGMA journal_mode = DELETE');
    $pdo->exec('VACUUM INTO '.$pdo->quote($destination));
    $pdo = null;
    validateSqlite($destination);
}

function safeRemoveOwnedStage(string $path, string $dataRoot): void
{
    $root = realpath($dataRoot);
    $candidateParent = realpath(dirname($path));
    if ($root && $candidateParent && str_starts_with($candidateParent.DIRECTORY_SEPARATOR, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
        removeTree($path);
    }
}

function freshInstall(string $dataRoot, string $archivePath, string $backendRoot, string $phpBinary, string $phpIni): void
{
    $databasePath = $dataRoot.DIRECTORY_SEPARATOR.'database.sqlite';
    if (is_file($databasePath)) {
        throw new RuntimeException('Ya existe una base local. El instalador no la reemplazará.');
    }

    ensureDirectory($dataRoot);
    $stage = $dataRoot.DIRECTORY_SEPARATOR.'.setup-import-'.bin2hex(random_bytes(8));
    $temporaryDatabase = $dataRoot.DIRECTORY_SEPARATOR.'.database-importing-'.bin2hex(random_bytes(8)).'.sqlite';
    ensureDirectory($stage);

    try {
        try {
            $package = verifyAndExtractPackage($archivePath, $stage.DIRECTORY_SEPARATOR.'package');
            $key = appKey($dataRoot);
            $sourceDatabase = $package['root'].DIRECTORY_SEPARATOR.'database.sqlite';
            runMigrations($phpBinary, $phpIni, $backendRoot, $dataRoot, $sourceDatabase, $key);
            repairAccessControlIfMissing($phpBinary, $phpIni, $backendRoot, $dataRoot, $sourceDatabase, $key);
            clearStalePermissionCache($sourceDatabase);
            validateAccessControlDatabase($sourceDatabase);
            validateSqlite($sourceDatabase, (string) $package['manifest']['tenant_slug']);

            $configTarget = $dataRoot.DIRECTORY_SEPARATOR.'config.json';
            $configText = file_get_contents($package['root'].DIRECTORY_SEPARATOR.'config.json');
            if (! is_string($configText)) {
                throw new RuntimeException('No se pudo leer la configuración de la empresa.');
            }
            if (is_file($configTarget)) {
                $existingConfig = json_decode((string) file_get_contents($configTarget), true);
                if (! is_array($existingConfig) || ($existingConfig['tenant_slug'] ?? null) !== $package['manifest']['tenant_slug']) {
                    throw new RuntimeException('Ya existe una configuración local de otra empresa; no se reemplazará.');
                }
            }

            // Complete and repair assets before database.sqlite marks the import as complete.
            $photoSource = $package['root'].DIRECTORY_SEPARATOR.'images';
            copyTree($photoSource, $dataRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public', true);

            $configTemporary = $configTarget.'.new-'.bin2hex(random_bytes(4));
            if (file_put_contents($configTemporary, $configText, LOCK_EX) === false || ! rename($configTemporary, $configTarget)) {
                @unlink($configTemporary);
                throw new RuntimeException('No se pudo guardar la configuración de la empresa.');
            }

            if (! copy($sourceDatabase, $temporaryDatabase)) {
                throw new RuntimeException('No se pudo preparar la base de datos importada.');
            }
            validateSqlite($temporaryDatabase, (string) $package['manifest']['tenant_slug']);
        } finally {
            safeRemoveOwnedStage($stage, $dataRoot);
        }

        // This final rename is the only step that marks a new local installation complete.
        if (is_file($databasePath) || ! rename($temporaryDatabase, $databasePath)) {
            throw new RuntimeException('No se pudo activar la base de datos importada.');
        }
    } catch (Throwable $exception) {
        @unlink($temporaryDatabase);
        throw $exception;
    }
}

function archiveDatabaseSet(string $database, string $backupDirectory, string $label): ?string
{
    if (! is_file($database)) {
        return null;
    }

    ensureDirectory($backupDirectory);
    $archive = $backupDirectory.DIRECTORY_SEPARATOR.$label.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';
    $moved = [];
    foreach (['-wal', '-shm'] as $suffix) {
        $source = $database.$suffix;
        if (! is_file($source)) {
            continue;
        }
        $target = $archive.$suffix;
        if (! rename($source, $target)) {
            foreach (array_reverse($moved) as $pair) {
                @rename($pair[1], $pair[0]);
            }
            throw new RuntimeException('No se pudieron conservar los archivos WAL/SHM de la base local.');
        }
        $moved[] = [$source, $target];
    }

    if (! rename($database, $archive)) {
        foreach (array_reverse($moved) as $pair) {
            @rename($pair[1], $pair[0]);
        }
        throw new RuntimeException('No se pudo resguardar la base local antes de recuperarla.');
    }

    return $archive;
}

function moveDatabaseSidecars(string $source, string $destination): void
{
    $moved = [];
    foreach (['-wal', '-shm'] as $suffix) {
        $from = $source.$suffix;
        if (! is_file($from)) {
            continue;
        }
        $to = $destination.$suffix;
        if (file_exists($to) || ! rename($from, $to)) {
            foreach (array_reverse($moved) as $pair) {
                @rename($pair[1], $pair[0]);
            }
            throw new RuntimeException('No se pudieron resguardar los archivos WAL/SHM de la base local.');
        }
        $moved[] = [$from, $to];
    }
}

function restoreDatabaseSidecars(string $source, string $destination): void
{
    foreach (['-wal', '-shm'] as $suffix) {
        $from = $source.$suffix;
        if (is_file($from) && ! rename($from, $destination.$suffix)) {
            throw new RuntimeException('No se pudieron restaurar los archivos WAL/SHM anteriores.');
        }
    }
}

function prepareUpgrade(string $dataRoot, string $sourceDatabase, string $legacyConfig, string $legacyEnv, string $legacyImages, string $backendRoot, string $phpBinary, string $phpIni): void
{
    $statePath = $dataRoot.DIRECTORY_SEPARATOR.'.update-state.json';
    if (is_file($statePath)) {
        throw new RuntimeException('Hay una actualización anterior pendiente de recuperación.');
    }

    ensureDirectory($dataRoot);
    $key = appKey($dataRoot, $legacyEnv);
    $stage = $dataRoot.DIRECTORY_SEPARATOR.'.setup-update-'.bin2hex(random_bytes(8));
    ensureDirectory($stage);
    $backupDirectory = $dataRoot.DIRECTORY_SEPARATOR.'backups';
    ensureDirectory($backupDirectory);
    $suffix = gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
    $backupName = 'database-before-update-'.$suffix.'.sqlite';
    $candidateName = '.database-candidate-'.$suffix.'.sqlite';
    $backupPath = $backupDirectory.DIRECTORY_SEPARATOR.$backupName;
    $candidatePath = $dataRoot.DIRECTORY_SEPARATOR.$candidateName;

    try {
        vacuumDatabase($sourceDatabase, $backupPath);
        vacuumDatabase($sourceDatabase, $candidatePath);
        runMigrations($phpBinary, $phpIni, $backendRoot, $dataRoot, $candidatePath, $key);
        repairAccessControlIfMissing($phpBinary, $phpIni, $backendRoot, $dataRoot, $candidatePath, $key);
        clearStalePermissionCache($candidatePath);
        validateAccessControlDatabase($candidatePath);
        validateSqlite($candidatePath);

        $hadProgramDataDatabase = is_file($dataRoot.DIRECTORY_SEPARATOR.'database.sqlite');
        $state = [
            'backup' => $backupName,
            'candidate' => $candidateName,
            'had_programdata_database' => $hadProgramDataDatabase,
            'source_was_programdata' => realpath($sourceDatabase) === realpath($dataRoot.DIRECTORY_SEPARATOR.'database.sqlite'),
            'created_at' => gmdate(DATE_ATOM),
            'legacy_config' => is_file($dataRoot.DIRECTORY_SEPARATOR.'config.json') ? false : (is_file($legacyConfig) ? $legacyConfig : null),
        ];
        // Finish preserving legacy images before publishing a pending-update marker.
        copyTree($legacyImages, $dataRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public');

        $temporaryState = $statePath.'.new';
        if (file_put_contents($temporaryState, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || ! rename($temporaryState, $statePath)) {
            @unlink($temporaryState);
            throw new RuntimeException('No se pudo guardar el estado de recuperación.');
        }
    } catch (Throwable $exception) {
        @unlink($candidatePath);
        try {
            $logDirectory = $dataRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs';
            ensureDirectory($logDirectory);
            $message = sprintf(
                "[%s] prepare-upgrade %s: %s%s",
                date(DATE_ATOM),
                $exception::class,
                $exception->getMessage(),
                PHP_EOL
            );
            file_put_contents($logDirectory.DIRECTORY_SEPARATOR.'installer.log', $message, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Keep the original update failure as the primary error.
        }
        throw $exception;
    } finally {
        safeRemoveOwnedStage($stage, $dataRoot);
    }
}

function activateUpgrade(string $dataRoot): void
{
    $statePath = $dataRoot.DIRECTORY_SEPARATOR.'.update-state.json';
    $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
    if (! is_array($state)) {
        throw new RuntimeException('No hay una actualización preparada.');
    }

    $candidateName = (string) ($state['candidate'] ?? '');
    $backupName = (string) ($state['backup'] ?? '');
    if (basename($candidateName) !== $candidateName || basename($backupName) !== $backupName) {
        throw new RuntimeException('El estado de actualización no es válido.');
    }
    $candidate = $dataRoot.DIRECTORY_SEPARATOR.$candidateName;
    $backup = $dataRoot.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.$backupName;
    $database = $dataRoot.DIRECTORY_SEPARATOR.'database.sqlite';
    validateSqlite($candidate);

    $promoting = $database.'.new';
    if (is_file($promoting)) {
        throw new RuntimeException('Existe una base temporal anterior; no se sobrescribirá.');
    }
    if (! copy($candidate, $promoting)) {
        throw new RuntimeException('No se pudo preparar la base de datos actualizada.');
    }
    validateSqlite($promoting);

    $previous = $database.'.previous';
    if (is_file($previous) || is_file($previous.'-wal') || is_file($previous.'-shm')) {
        @unlink($promoting);
        throw new RuntimeException('Existe una base de recuperación previa; no se sobrescribirá.');
    }
    if (is_file($database)) {
        moveDatabaseSidecars($database, $previous);
        if (! rename($database, $previous)) {
            restoreDatabaseSidecars($previous, $database);
            @unlink($promoting);
            throw new RuntimeException('No se pudo resguardar la base anterior.');
        }
    }

    if (! rename($promoting, $database)) {
        if (is_file($previous)) {
            @rename($previous, $database);
            restoreDatabaseSidecars($previous, $database);
        }
        throw new RuntimeException('No se pudo activar la base actualizada.');
    }

    if (! is_file($dataRoot.DIRECTORY_SEPARATOR.'config.json') && is_string($state['legacy_config'] ?? null) && is_file($state['legacy_config'])) {
        $legacyConfig = (string) file_get_contents($state['legacy_config']);
        $temporaryConfig = $dataRoot.DIRECTORY_SEPARATOR.'config.json.new';
        if (file_put_contents($temporaryConfig, $legacyConfig, LOCK_EX) === false || ! rename($temporaryConfig, $dataRoot.DIRECTORY_SEPARATOR.'config.json')) {
            @unlink($temporaryConfig);
            throw new RuntimeException('No se pudo conservar la configuración de la empresa.');
        }
    }

    $state['activated'] = true;
    $state['previous_database'] = is_file($previous) ? basename($previous) : null;
    $state['rollback_backup'] = basename($backup);
    $temporaryState = $statePath.'.new';
    if (file_put_contents($temporaryState, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || ! rename($temporaryState, $statePath)) {
        @unlink($temporaryState);
        throw new RuntimeException('No se pudo guardar el estado de recuperación de la base activada.');
    }
}

function rollbackUpgrade(string $dataRoot): void
{
    $statePath = $dataRoot.DIRECTORY_SEPARATOR.'.update-state.json';
    $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
    if (! is_array($state)) {
        throw new RuntimeException('No hay una actualización que recuperar.');
    }

    $database = $dataRoot.DIRECTORY_SEPARATOR.'database.sqlite';
    $previous = $database.'.previous';
    $backupDirectory = $dataRoot.DIRECTORY_SEPARATOR.'backups';

    if (is_file($previous)) {
        // Preserve the failed candidate and any uncheckpointed writes before restoring the old database set.
        archiveDatabaseSet($database, $backupDirectory, 'database-failed-update');
        if (! rename($previous, $database)) {
            throw new RuntimeException('No se pudo recuperar la base de datos anterior.');
        }
        restoreDatabaseSidecars($previous, $database);
    } elseif (! ($state['had_programdata_database'] ?? false)) {
        // The legacy database remains in the rollback program folder; keep a copy of the candidate in ProgramData.
        archiveDatabaseSet($database, $backupDirectory, 'database-failed-update');
    }

    $candidateName = (string) ($state['candidate'] ?? '');
    if ($candidateName !== '' && basename($candidateName) === $candidateName) {
        @unlink($dataRoot.DIRECTORY_SEPARATOR.$candidateName);
    }
    @unlink($statePath);
}

function commitUpgrade(string $dataRoot): void
{
    $statePath = $dataRoot.DIRECTORY_SEPARATOR.'.update-state.json';
    $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
    if (! is_array($state) || ! ($state['activated'] ?? false)) {
        throw new RuntimeException('No se puede finalizar una actualización no activada.');
    }

    $candidate = (string) ($state['candidate'] ?? '');
    if ($candidate !== '' && basename($candidate) === $candidate) {
        @unlink($dataRoot.DIRECTORY_SEPARATOR.$candidate);
    }
    $previous = $dataRoot.DIRECTORY_SEPARATOR.'database.sqlite.previous';
    if (is_file($previous)) {
        $backupDirectory = $dataRoot.DIRECTORY_SEPARATOR.'backups';
        $archived = archiveDatabaseSet($previous, $backupDirectory, 'database-previous-version');
        if ($archived === null) {
            throw new RuntimeException('No se pudo conservar la base de la versión anterior.');
        }
    }
    @unlink($statePath);
}

function run(): void
{
    requireExtensions();
    $action = $GLOBALS['argv'][1] ?? '';
    $args = array_slice($GLOBALS['argv'], 2);

    switch ($action) {
        case 'fresh':
            if (count($args) !== 5) {
                fail('Parámetros incompletos para una instalación nueva.');
            }
            freshInstall($args[0], $args[1], $args[2], $args[3], $args[4]);
            break;
        case 'prepare-upgrade':
            if (count($args) !== 8) {
                fail('Parámetros incompletos para preparar la actualización.');
            }
            prepareUpgrade($args[0], $args[1], $args[2], $args[3], $args[4], $args[5], $args[6], $args[7]);
            break;
        case 'activate-upgrade':
            if (count($args) !== 1) {
                fail('Parámetros incompletos para activar la actualización.');
            }
            activateUpgrade($args[0]);
            break;
        case 'rollback-upgrade':
            if (count($args) !== 1) {
                fail('Parámetros incompletos para recuperar la actualización.');
            }
            rollbackUpgrade($args[0]);
            break;
        case 'commit-upgrade':
            if (count($args) !== 1) {
                fail('Parámetros incompletos para finalizar la actualización.');
            }
            commitUpgrade($args[0]);
            break;
        default:
            fail('Acción del instalador no reconocida.');
    }
}

if (getenv('BALANZAPRO_INSTALLER_HELPER_LIBRARY_ONLY') !== '1') {
    try {
        run();
        fwrite(STDOUT, 'Comprobaciones locales completadas.'.PHP_EOL);
    } catch (Throwable $exception) {
        fail($exception->getMessage());
    }
}
