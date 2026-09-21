<?php

// scripts/export_asiamoto_to_sqlite.php
// Exporta la base de datos completa de COMERCIAL EL ASIATICO 88 C.A (asiamoto, tenant_id = 4)
// desde PostgreSQL (inventory_balanzapro) hacia un archivo SQLite autónomo.

$sqlitePath = '/root/backups/asiamoto_20260920/inventario.sqlite';
$targetDir = dirname($sqlitePath);
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

if (file_exists($sqlitePath)) {
    unlink($sqlitePath);
}
touch($sqlitePath);

echo "==> 1. Ejecutando migraciones de Laravel en SQLite: {$sqlitePath}...\n";
$cmd = sprintf(
    'DB_CONNECTION=sqlite DB_DATABASE=%s php /opt/balanzapro-cloud/artisan migrate --force 2>&1',
    escapeshellarg($sqlitePath)
);
exec($cmd, $migrateOutput, $migrateCode);
if ($migrateCode !== 0) {
    echo "ERROR en migraciones SQLite:\n" . implode("\n", $migrateOutput) . "\n";
    exit(1);
}
echo "   Migraciones completadas correctamente.\n";

echo "==> 2. Conectando a PostgreSQL (inventory_balanzapro) y SQLite...\n";
$pg = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=inventory_balanzapro', 'postgres', 'GaboMac12', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$sqlite = new PDO("sqlite:{$sqlitePath}", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$sqlite->exec('PRAGMA foreign_keys = OFF;');
$sqlite->exec('PRAGMA synchronous = OFF;');
$sqlite->exec('PRAGMA journal_mode = MEMORY;');

$tenantId = 4;

// Definición de tablas y sus filtros
$tables = [
    // 1. Tenancy y configuración
    'tenants' => "SELECT * FROM tenants WHERE id = {$tenantId}",
    'tenant_capabilities' => "SELECT * FROM tenant_capabilities WHERE tenant_id = {$tenantId}",
    'tenant_settings' => "SELECT * FROM tenant_settings WHERE tenant_id = {$tenantId}",
    
    // 2. Usuarios y autenticación
    'users' => "SELECT * FROM users WHERE id IN (SELECT user_id FROM tenant_user WHERE tenant_id = {$tenantId}) OR is_platform_admin = true",
    'tenant_user' => "SELECT * FROM tenant_user WHERE tenant_id = {$tenantId}",
    'roles' => "SELECT * FROM roles WHERE tenant_id = {$tenantId} OR tenant_id IS NULL",
    'permissions' => "SELECT * FROM permissions",
    'model_has_roles' => "SELECT * FROM model_has_roles WHERE tenant_id = {$tenantId} OR tenant_id IS NULL",
    'model_has_permissions' => "SELECT * FROM model_has_permissions WHERE tenant_id = {$tenantId} OR tenant_id IS NULL",
    'role_has_permissions' => "SELECT * FROM role_has_permissions WHERE role_id IN (SELECT id FROM roles WHERE tenant_id = {$tenantId} OR tenant_id IS NULL)",

    // 3. Estructura física
    'branches' => "SELECT * FROM branches WHERE tenant_id = {$tenantId}",
    'warehouses' => "SELECT * FROM warehouses WHERE tenant_id = {$tenantId}",
    'warehouse_locations' => "SELECT * FROM warehouse_locations WHERE tenant_id = {$tenantId}",

    // 4. Monedas y precios
    'exchange_rate_types' => "SELECT * FROM exchange_rate_types WHERE tenant_id = {$tenantId}",
    'exchange_rates' => "SELECT * FROM exchange_rates WHERE tenant_id = {$tenantId}",
    'payment_methods' => "SELECT * FROM payment_methods WHERE tenant_id = {$tenantId}",
    'price_lists' => "SELECT * FROM price_lists WHERE tenant_id = {$tenantId}",
    'price_list_payment_method' => "SELECT * FROM price_list_payment_method WHERE price_list_id IN (SELECT id FROM price_lists WHERE tenant_id = {$tenantId})",

    // 5. Catálogo
    'brands' => "SELECT * FROM brands WHERE tenant_id = {$tenantId}",
    'categories' => "SELECT * FROM categories WHERE tenant_id = {$tenantId}",
    'tags' => "SELECT * FROM tags WHERE tenant_id = {$tenantId}",
    'warranty_policies' => "SELECT * FROM warranty_policies WHERE tenant_id = {$tenantId}",
    'suppliers' => "SELECT * FROM suppliers WHERE tenant_id = {$tenantId}",
    'customers' => "SELECT * FROM customers WHERE tenant_id = {$tenantId}",
    'customer_groups' => "SELECT * FROM customer_groups WHERE tenant_id = {$tenantId}",
    'products' => "SELECT * FROM products WHERE tenant_id = {$tenantId}",
    'product_category' => "SELECT * FROM product_category WHERE product_id IN (SELECT id FROM products WHERE tenant_id = {$tenantId})",
    'product_tag' => "SELECT * FROM product_tag WHERE product_id IN (SELECT id FROM products WHERE tenant_id = {$tenantId})",
    'product_prices' => "SELECT * FROM product_prices WHERE tenant_id = {$tenantId}",
    'product_units' => "SELECT * FROM product_units WHERE tenant_id = {$tenantId}",
    'product_variants' => "SELECT * FROM product_variants WHERE product_id IN (SELECT id FROM products WHERE tenant_id = {$tenantId})",
    'product_images' => "SELECT * FROM product_images WHERE tenant_id = {$tenantId}",
    'product_image_variants' => "SELECT * FROM product_image_variants WHERE product_image_id IN (SELECT id FROM product_images WHERE tenant_id = {$tenantId})",

    // 6. Inventario y stock
    'stock_balances' => "SELECT * FROM stock_balances WHERE tenant_id = {$tenantId}",
    'stock_movements' => "SELECT * FROM stock_movements WHERE tenant_id = {$tenantId}",
    'stock_counts' => "SELECT * FROM stock_counts WHERE tenant_id = {$tenantId}",
    'stock_count_items' => "SELECT * FROM stock_count_items WHERE stock_count_id IN (SELECT id FROM stock_counts WHERE tenant_id = {$tenantId})",
    'inventory_manual_movements' => "SELECT * FROM inventory_manual_movements WHERE tenant_id = {$tenantId}",

    // 7. Cajas registradoras y sesiones
    'cash_registers' => "SELECT * FROM cash_registers WHERE tenant_id = {$tenantId}",
    'cash_register_sessions' => "SELECT * FROM cash_register_sessions WHERE tenant_id = {$tenantId}",
    'cash_register_movements' => "SELECT * FROM cash_register_movements WHERE tenant_id = {$tenantId}",
    'cash_register_session_counts' => "SELECT * FROM cash_register_session_counts WHERE session_id IN (SELECT id FROM cash_register_sessions WHERE tenant_id = {$tenantId})",

    // 8. Compras
    'purchase_orders' => "SELECT * FROM purchase_orders WHERE tenant_id = {$tenantId}",
    'purchase_items' => "SELECT * FROM purchase_items WHERE purchase_order_id IN (SELECT id FROM purchase_orders WHERE tenant_id = {$tenantId})",
    'purchase_returns' => "SELECT * FROM purchase_returns WHERE tenant_id = {$tenantId}",
    'purchase_return_items' => "SELECT * FROM purchase_return_items WHERE purchase_return_id IN (SELECT id FROM purchase_returns WHERE tenant_id = {$tenantId})",

    // 9. Ventas y POS
    'sales' => "SELECT * FROM sales WHERE tenant_id = {$tenantId}",
    'sale_items' => "SELECT * FROM sale_items WHERE sale_id IN (SELECT id FROM sales WHERE tenant_id = {$tenantId})",
    'pos_orders' => "SELECT * FROM pos_orders WHERE tenant_id = {$tenantId}",
    'pos_payments' => "SELECT * FROM pos_payments WHERE tenant_id = {$tenantId}",
    'sale_reversals' => "SELECT * FROM sale_reversals WHERE tenant_id = {$tenantId}",
    'sales_returns' => "SELECT * FROM sales_returns WHERE tenant_id = {$tenantId}",
    'sales_return_items' => "SELECT * FROM sales_return_items WHERE sales_return_id IN (SELECT id FROM sales_returns WHERE tenant_id = {$tenantId})",

    // 10. Cotizaciones y promociones
    'quotations' => "SELECT * FROM quotations WHERE tenant_id = {$tenantId}",
    'quotation_items' => "SELECT * FROM quotation_items WHERE quotation_id IN (SELECT id FROM quotations WHERE tenant_id = {$tenantId})",
    'promotions' => "SELECT * FROM promotions WHERE tenant_id = {$tenantId}",
    'promotion_items' => "SELECT * FROM promotion_items WHERE promotion_id IN (SELECT id FROM promotions WHERE tenant_id = {$tenantId})",
    'sale_promotion_applications' => "SELECT * FROM sale_promotion_applications WHERE sale_id IN (SELECT id FROM sales WHERE tenant_id = {$tenantId})",

    // 11. Cuentas por cobrar y pagar
    'accounts_receivables' => "SELECT * FROM accounts_receivables WHERE tenant_id = {$tenantId}",
    'accounts_receivable_payments' => "SELECT * FROM accounts_receivable_payments WHERE tenant_id = {$tenantId}",
    'accounts_payables' => "SELECT * FROM accounts_payables WHERE tenant_id = {$tenantId}",
    'accounts_payable_payments' => "SELECT * FROM accounts_payable_payments WHERE tenant_id = {$tenantId}",
    'accounts_payable_payment_requests' => "SELECT * FROM accounts_payable_payment_requests WHERE tenant_id = {$tenantId}",
];

echo "==> 3. Copiando registros de PostgreSQL a SQLite...\n";

$sqlite->beginTransaction();
$totalRecords = 0;

foreach ($tables as $table => $sql) {
    // Comprobar si la tabla existe en SQLite
    $check = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch();
    if (!$check) {
        echo "   [SKIP] Tabla {$table} no existe en SQLite.\n";
        continue;
    }

    // Obtener columnas de SQLite
    $colsStmt = $sqlite->query("PRAGMA table_info({$table})");
    $sqliteCols = array_column($colsStmt->fetchAll(), 'name');

    // Extraer datos de PG
    try {
        $pgStmt = $pg->query($sql);
        $rows = $pgStmt->fetchAll();
    } catch (Exception $e) {
        echo "   [WARN] No se pudo consultar {$table} en PG: " . $e->getMessage() . "\n";
        continue;
    }

    $count = count($rows);
    if ($count === 0) {
        continue;
    }

    // Insertar por lotes
    $firstRow = $rows[0];
    $validCols = array_intersect(array_keys($firstRow), $sqliteCols);
    $colList = implode(', ', array_map(fn($c) => "\"{$c}\"", $validCols));
    $placeholders = implode(', ', array_fill(0, count($validCols), '?'));

    $insertSql = "INSERT OR REPLACE INTO \"{$table}\" ({$colList}) VALUES ({$placeholders})";
    $insertStmt = $sqlite->prepare($insertSql);

    foreach ($rows as $row) {
        $values = [];
        foreach ($validCols as $col) {
            $val = $row[$col];
            // Convertir booleanos de postgres ('t'/'f') a enteros 1/0 para SQLite
            if ($val === true || $val === 't') {
                $val = 1;
            } elseif ($val === false || $val === 'f') {
                $val = 0;
            }
            $values[] = $val;
        }
        $insertStmt->execute($values);
    }

    $totalRecords += $count;
    printf("   %-32s : %6d registros\n", $table, $count);
}

// Configuración de sincronización local
echo "==> 4. Configurando mapeo de sincronización local...\n";
$sqlite->exec("
    INSERT OR REPLACE INTO sync_tenant_mappings (
        local_tenant_id, remote_tenant_id, remote_parent_id, remote_slug, is_group, created_at, updated_at
    ) VALUES (
        {$tenantId}, {$tenantId}, NULL, 'asiamoto', 1, datetime('now'), datetime('now')
    );
");

$sqlite->commit();

$sqlite->exec('PRAGMA foreign_keys = ON;');
$sqlite->exec('VACUUM;');
$sqlite->exec('ANALYZE;');

$sizeMb = round(filesize($sqlitePath) / (1024 * 1024), 2);
echo "==> EXITO: Base de datos SQLite creada en {$sqlitePath} ({$sizeMb} MB, {$totalRecords} registros copiados).\n";
