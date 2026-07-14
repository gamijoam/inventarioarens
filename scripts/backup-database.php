<?php

require 'C:\Users\gafit\Documents\INVENTARIOARENS\vendor\autoload.php';
$app = require 'C:\Users\gafit\Documents\INVENTARIOARENS\bootstrap\app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dsn = 'pgsql:host=127.0.0.1;port=5434;dbname=inventory_arens';
$pdo = new PDO($dsn, 'inventory_arens', 'secret');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$backupDir = 'C:\Users\gafit\Desktop\INVENTARIOARENS-backups';
if (! is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

$ts = date('Ymd-His');
$backupFile = "{$backupDir}/inventory_arens-backup-{$ts}.sql";

$tables = $pdo->query("
    SELECT tablename FROM pg_tables
    WHERE schemaname = 'public'
    ORDER BY tablename
")->fetchAll(PDO::FETCH_COLUMN);

$totalRows = 0;
$out = fopen($backupFile, 'w');
fwrite($out, "-- INVENTARIOARENS backup generated " . date('c') . "\n");
fwrite($out, "-- Host: 127.0.0.1:5434, DB: inventory_arens\n");
fwrite($out, "-- Restore: psql -h 127.0.0.1 -p 5434 -U inventory_arens -d inventory_arens -f {$backupFile}\n\n");

foreach ($tables as $table) {
    $count = (int) $pdo->query("SELECT count(*) FROM {$table}")->fetchColumn();
    $totalRows += $count;
    echo str_pad($table, 30) . str_pad((string) $count, 10) . " rows\n";

    if ($count === 0) {
        continue;
    }

    fwrite($out, "\n-- ===== {$table} ({$count} rows) =====\n");

    $cols = $pdo->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_name = '{$table}' ORDER BY ordinal_position
    ")->fetchAll(PDO::FETCH_COLUMN);

    $rows = $pdo->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);

    foreach (array_chunk($rows, 500) as $chunk) {
        $values = [];
        foreach ($chunk as $row) {
            $escaped = array_map(function ($v) use ($pdo) {
                if ($v === null) {
                    return 'NULL';
                }
                return $pdo->quote((string) $v);
            }, $row);
            $values[] = '(' . implode(',', $escaped) . ')';
        }
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES ' . implode(",\n", $values) . ";\n";
        fwrite($out, $sql);
    }
}

fclose($out);

echo "\n--- TOTAL: {$totalRows} filas respaldadas ---\n";
echo "Backup: {$backupFile}\n";
echo "Size: " . round(filesize($backupFile) / 1024, 2) . " KB\n";