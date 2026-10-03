<?php

declare(strict_types=1);

/**
 * Actualiza los precios de superleopard (tenant_id = 5) desde
 * DOCUMENTOS/doraddo-1.csv (sin #REF!).
 *
 * Mapeo (literal segun las etiquetas del archivo):
 *   col C "recios 3" -> lista P3
 *   col D "recios 2" -> lista P2
 *   col E "recios 1" -> lista P1  (y precio base)
 *   col F "cost"     -> costo (last_purchase_cost / average_cost)
 *
 * Cruza por "codiggo" (col B) contra products.sku. Para codigos repetidos
 * replica la logica del import original: sku, sku-2, sku-3...
 */

$csvPath = '/opt/balanzapro-cloud/DOCUMENTOS/doraddo-1.csv';
$tenantId = 5;
$apply = in_array('--apply', $argv, true);

if (! file_exists($csvPath)) {
    echo "ERROR: no existe {$csvPath}\n";
    exit(1);
}

echo "==> Conectando a PostgreSQL...\n";
$pg = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=inventory_balanzapro', 'postgres', 'GaboMac12', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$plIds = [];
foreach (['P1', 'P2', 'P3'] as $code) {
    $stmt = $pg->prepare('SELECT id FROM price_lists WHERE tenant_id = ? AND code = ?');
    $stmt->execute([$tenantId, $code]);
    $plIds[$code] = (int) $stmt->fetchColumn();
}
if ($plIds['P1'] <= 0 || $plIds['P2'] <= 0 || $plIds['P3'] <= 0) {
    echo "ERROR: faltan listas P1/P2/P3 para el tenant {$tenantId}\n";
    exit(1);
}
echo '==> Listas: P1='.$plIds['P1'].' P2='.$plIds['P2'].' P3='.$plIds['P3']."\n";

$parseNum = function (?string $val): ?float {
    if ($val === null || trim($val) === '' || trim($val) === '#REF!') {
        return null;
    }
    $clean = str_replace([' ', ','], ['', '.'], trim($val));

    return is_numeric($clean) ? (float) $clean : null;
};

$findProduct = $pg->prepare('SELECT id FROM products WHERE tenant_id = ? AND sku = ? LIMIT 1');
$updateProduct = $pg->prepare('
    UPDATE products
       SET base_price = COALESCE(?, base_price),
           last_purchase_cost = COALESCE(?, last_purchase_cost),
           average_cost = COALESCE(?, average_cost),
           updated_at = ?
     WHERE id = ? AND tenant_id = ?
');
$updatePrice = $pg->prepare('
    UPDATE product_prices
       SET price = ?, currency = \'USD\', is_active = true, updated_at = ?
     WHERE tenant_id = ? AND product_id = ? AND price_list_id = ?
');
$insertPrice = $pg->prepare('
    INSERT INTO product_prices (tenant_id, product_id, price_list_id, price, currency, is_active, created_at, updated_at)
    VALUES (?, ?, ?, ?, \'USD\', true, ?, ?)
');

$handle = fopen($csvPath, 'r');
// Saltar 3 filas de encabezado (etiquetas + 2 encabezados chinos)
fgetcsv($handle);
fgetcsv($handle);
fgetcsv($handle);

$seen = [];
$now = date('Y-m-d H:i:s');
$count = 0;
$updated = 0;
$unmatched = [];
$priceUpdates = 0;
$priceInserts = 0;

if ($apply) {
    $pg->beginTransaction();
}

while (($row = fgetcsv($handle)) !== false) {
    $code = trim((string) ($row[1] ?? ''));
    if ($code === '') {
        continue;
    }

    $seen[$code] = ($seen[$code] ?? 0) + 1;
    $sku = $seen[$code] === 1 ? $code : $code.'-'.$seen[$code];

    $p3 = $parseNum($row[2] ?? null); // recios 3 -> P3
    $p2 = $parseNum($row[3] ?? null); // recios 2 -> P2
    $p1 = $parseNum($row[4] ?? null); // recios 1 -> P1
    $cost = $parseNum($row[5] ?? null);

    $findProduct->execute([$tenantId, $sku]);
    $productId = (int) $findProduct->fetchColumn();
    if ($productId <= 0) {
        // Normalizar sufijos ".00" (el CSV y la BD no siempre coinciden).
        $base = (string) preg_replace('/\.0+$/', '', $sku);
        foreach (array_unique([$base, $base.'.00']) as $alt) {
            if ($alt === '' || $alt === $sku) {
                continue;
            }
            $findProduct->execute([$tenantId, $alt]);
            $productId = (int) $findProduct->fetchColumn();
            if ($productId > 0) {
                break;
            }
        }
    }
    if ($productId <= 0) {
        $findProduct->execute([$tenantId, $code]);
        $productId = (int) $findProduct->fetchColumn();
    }
    if ($productId <= 0) {
        $unmatched[] = $code.' ('.$sku.')';
        continue;
    }

    $count++;

    if ($apply) {
        $updateProduct->execute([$p1, $cost, $cost, $now, $productId, $tenantId]);

        foreach (['P1' => $p1, 'P2' => $p2, 'P3' => $p3] as $listCode => $value) {
            if ($value === null) {
                continue;
            }
            $updatePrice->execute([$value, $now, $tenantId, $productId, $plIds[$listCode]]);
            if ($updatePrice->rowCount() === 0) {
                $insertPrice->execute([$tenantId, $productId, $plIds[$listCode], $value, $now, $now]);
                $priceInserts++;
            } else {
                $priceUpdates++;
            }
        }
        $updated++;
    }
}

fclose($handle);

if ($apply) {
    $pg->commit();
}

echo "\n==> Resumen\n";
echo '   Productos en CSV:        '.array_sum($seen)."\n";
echo '   Productos cruzados:      '.$count."\n";
echo '   Productos actualizados:  '.$updated."\n";
echo '   Precios P1/P2/P3 update: '.($apply ? $priceUpdates : '(dry-run)')."\n";
echo '   Precios insertados:      '.($apply ? $priceInserts : '(dry-run)')."\n";
echo '   Sin match:               '.count($unmatched)."\n";
if ($unmatched !== []) {
    file_put_contents('/tmp/opencode/backup_superleopard/unmatched_doraddo1.txt', implode("\n", $unmatched));
    echo "   (lista guardada en /tmp/opencode/backup_superleopard/unmatched_doraddo1.txt)\n";
    echo '   primeros: '.implode(', ', array_slice($unmatched, 0, 15))."\n";
}
echo $apply ? "\nLISTO (aplicado).\n" : "\nDRY-RUN (nada aplicado; usa --apply).\n";
