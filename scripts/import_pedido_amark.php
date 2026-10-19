<?php

declare(strict_types=1);

/**
 * Importa los productos de pedido amark.xlsx (desde /tmp/pedido_amark.json)
 * a Super Leopard (tenant_id = 5).
 * 
 * Regla del usuario:
 * "solo el sku y el nombre es todo los precios los puedes dejar en 0"
 */

$jsonPath = '/tmp/pedido_amark.json';
if (!file_exists($jsonPath)) {
    echo "ERROR: {$jsonPath} no existe\n";
    exit(1);
}

$items = json_decode(file_get_contents($jsonPath), true);
if (!is_array($items)) {
    echo "ERROR: JSON invalido\n";
    exit(1);
}

$tenantId = 5;
$warehouseId = 4; // ALMACEN PRINCIPAL
$brandId = 23;     // AMMARK
$categoryId = 34;  // AMMARK

echo "==> Conectando a PostgreSQL...\n";
$pg = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=inventory_balanzapro', 'postgres', 'GaboMac12', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Obtener IDs de las listas de precios de Super Leopard
$plStmt = $pg->prepare("SELECT id, code FROM price_lists WHERE tenant_id = ?");
$plStmt->execute([$tenantId]);
$priceLists = $plStmt->fetchAll();
$priceListIds = array_column($priceLists, 'id');
echo "==> Listas de precios encontradas para tenant {$tenantId}: " . implode(', ', $priceListIds) . "\n";

$findStmt = $pg->prepare("SELECT id, sku, name, base_price FROM products WHERE tenant_id = ? AND sku = ?");
$updateNameStmt = $pg->prepare("UPDATE products SET name = ?, brand_id = COALESCE(brand_id, ?), updated_at = NOW() WHERE id = ? AND tenant_id = ?");

$insertProductStmt = $pg->prepare("
    INSERT INTO products (
        tenant_id, name, sku, is_active, tracking_type, base_price,
        sale_currency, unit_of_measure, track_stock, average_cost,
        last_purchase_cost, brand_id, pricing_mode, created_at, updated_at
    ) VALUES (
        ?, ?, ?, true, 'quantity', 0.0000,
        'USD', 'unit', true, 0.0000,
        0.0000, ?, 'automatic', NOW(), NOW()
    ) RETURNING id
");

$insertCategoryStmt = $pg->prepare("
    INSERT INTO product_category (tenant_id, product_id, category_id)
    VALUES (?, ?, ?)
    ON CONFLICT DO NOTHING
");

$insertStockBalanceStmt = $pg->prepare("
    INSERT INTO stock_balances (tenant_id, warehouse_id, product_id, quantity_available, quantity_reserved, quantity_damaged, updated_at)
    VALUES (?, ?, ?, 0.0000, 0.0000, 0.0000, NOW())
    ON CONFLICT DO NOTHING
");

$insertPriceStmt = $pg->prepare("
    INSERT INTO product_prices (tenant_id, product_id, price_list_id, price, currency, is_active, created_at, updated_at)
    VALUES (?, ?, ?, 0.0000, 'USD', true, NOW(), NOW())
    ON CONFLICT DO NOTHING
");

$pg->beginTransaction();

$createdCount = 0;
$updatedCount = 0;
$createdList = [];
$updatedList = [];

foreach ($items as $item) {
    $sku = trim((string)($item['sku'] ?? ''));
    $name = trim((string)($item['name'] ?? ''));

    if ($sku === '' || $name === '') {
        continue;
    }

    $findStmt->execute([$tenantId, $sku]);
    $existing = $findStmt->fetch();

    if ($existing) {
        // Ya existe: actualizamos el nombre al oficial de la orden sin alterar sus precios vigentes
        $updateNameStmt->execute([$name, $brandId, $existing['id'], $tenantId]);
        $updatedCount++;
        $updatedList[] = "SKU: {$sku} | Anterior: {$existing['name']} -> Nuevo: {$name}";
    } else {
        // Nuevo: insertar producto con precios en 0
        $insertProductStmt->execute([$tenantId, $name, $sku, $brandId]);
        $newId = (int)$insertProductStmt->fetchColumn();

        // Categoría
        $insertCategoryStmt->execute([$tenantId, $newId, $categoryId]);

        // Stock balance inicial (0)
        $insertStockBalanceStmt->execute([$tenantId, $warehouseId, $newId]);

        // Precios en las 3 listas (0.0000)
        foreach ($priceListIds as $plId) {
            $insertPriceStmt->execute([$tenantId, $newId, $plId]);
        }

        $createdCount++;
        $createdList[] = "ID: {$newId} | SKU: {$sku} | {$name}";
    }
}

$pg->commit();

echo "\n================ RESUMEN DE IMPORTACIÓN ================\n";
echo "Total leídos del Excel:    " . count($items) . "\n";
echo "Nuevos productos creados:  {$createdCount}\n";
echo "Productos ya existentes:   {$updatedCount} (nombre actualizado, precios intactos)\n";
echo "========================================================\n\n";

if (!empty($createdList)) {
    echo "--- Productos Creados (Precios en 0.0000) ---\n";
    foreach ($createdList as $line) {
        echo "  + {$line}\n";
    }
}

if (!empty($updatedList)) {
    echo "\n--- Productos Ya Existentes Actualizados ---\n";
    foreach ($updatedList as $line) {
        echo "  * {$line}\n";
    }
}
