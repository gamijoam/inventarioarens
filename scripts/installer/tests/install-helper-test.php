<?php

declare(strict_types=1);

putenv('BALANZAPRO_INSTALLER_HELPER_LIBRARY_ONLY=1');
require dirname(__DIR__).'/install-helper.php';

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function makePackage(string $directory, bool $tamperHash = false, bool $addTraversal = false): string
{
    ensureDirectory($directory);
    $database = $directory.DIRECTORY_SEPARATOR.'database.sqlite';
    $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE)');
    $pdo->exec("INSERT INTO tenants (slug) VALUES ('fixture-company')");
    $pdo = null;

    $config = ['tenant_slug' => 'fixture-company', 'company_name' => 'Empresa de prueba'];
    $configPath = $directory.DIRECTORY_SEPARATOR.'config.json';
    file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_SLASHES));

    $fileEntries = [];
    foreach (['database.sqlite' => $database, 'config.json' => $configPath] as $name => $path) {
        $fileEntries[$name] = [
            'size_bytes' => filesize($path),
            'sha256' => $tamperHash && $name === 'config.json' ? str_repeat('0', 64) : hash_file('sha256', $path),
        ];
    }

    $manifest = [
        'format' => 'balanzapro-offline-package',
        'format_version' => 1,
        'tenant_slug' => 'fixture-company',
        'company_name' => 'Empresa de prueba',
        'database_integrity' => 'ok',
        'files' => $fileEntries,
    ];
    $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
    file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES));

    $archivePath = $directory.DIRECTORY_SEPARATOR.'package.zip';
    $zip = new ZipArchive();
    check($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'No se pudo crear el ZIP de prueba.');
    $zip->addFile($database, 'database.sqlite');
    $zip->addFile($configPath, 'config.json');
    $zip->addFile($manifestPath, 'manifest.json');
    if ($addTraversal) {
        $zip->addFromString('../outside.txt', 'no debe salir del staging');
    }
    check($zip->close(), 'No se pudo cerrar el ZIP de prueba.');

    return $archivePath;
}

$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'balanzapro-installer-test-'.bin2hex(random_bytes(5));
ensureDirectory($root);

try {
    $validArchive = makePackage($root.DIRECTORY_SEPARATOR.'valid');
    $extracted = verifyAndExtractPackage($validArchive, $root.DIRECTORY_SEPARATOR.'extracted');
    check($extracted['manifest']['tenant_slug'] === 'fixture-company', 'No se detectó la empresa del manifiesto.');
    check(is_file($extracted['root'].DIRECTORY_SEPARATOR.'database.sqlite'), 'No se extrajo la base de datos.');

    $badHashArchive = makePackage($root.DIRECTORY_SEPARATOR.'bad-hash', true);
    try {
        verifyAndExtractPackage($badHashArchive, $root.DIRECTORY_SEPARATOR.'bad-hash-out');
        throw new RuntimeException('El verificador aceptó un hash alterado.');
    } catch (RuntimeException $exception) {
        check(str_contains($exception->getMessage(), 'SHA-256'), 'El error de hash no fue claro.');
    }

    $traversalArchive = makePackage($root.DIRECTORY_SEPARATOR.'traversal', false, true);
    try {
        verifyAndExtractPackage($traversalArchive, $root.DIRECTORY_SEPARATOR.'traversal-out');
        throw new RuntimeException('El verificador aceptó una ruta fuera del ZIP.');
    } catch (RuntimeException $exception) {
        check(! is_file($root.DIRECTORY_SEPARATOR.'outside.txt'), 'El ZIP escribió fuera de su staging.');
    }

    $database = $root.DIRECTORY_SEPARATOR.'database.sqlite';
    file_put_contents($database, 'database');
    file_put_contents($database.'-wal', 'pending wal');
    file_put_contents($database.'-shm', 'pending shm');
    $archived = archiveDatabaseSet($database, $root.DIRECTORY_SEPARATOR.'backups', 'database-test');
    check(is_string($archived) && is_file($archived), 'No se archivó la base antes de recuperarla.');
    check(is_file($archived.'-wal') && is_file($archived.'-shm'), 'No se conservaron WAL y SHM junto a la base.');

    fwrite(STDOUT, "Pruebas de paquete y recuperación del instalador: OK\n");
} finally {
    removeTree($root);
}
