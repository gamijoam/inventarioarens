<?php

declare(strict_types=1);

/**
 * Carga ~50 productos de Minimarket en el tenant Demo (id 2) de BalanzaPro.
 * - Precio, costo, foto (placehold.co) y stock positivo en Almacen1 (warehouse 2).
 * - Crea categoria "Minimarket" y listas P3 (Detal, default), P1, P2.
 * - Idempotente: borra los productos MIN-% previos del tenant antes de cargar.
 */

$tenantId = 2;
$warehouseId = 2;

$items = [
    ['Harina de Maiz PAN 1kg', 1.30, 0.90],
    ['Arroz Blanco 1kg', 1.15, 0.80],
    ['Pasta Larga 1kg', 1.20, 0.85],
    ['Aceite Vegetal 1L', 2.50, 1.80],
    ['Azucar Refinada 1kg', 1.05, 0.70],
    ['Cafe Molido 500g', 3.10, 2.20],
    ['Leche en Polvo 400g', 3.60, 2.50],
    ['Leche Liquida 1L', 1.90, 1.30],
    ['Mantequilla 250g', 2.30, 1.60],
    ['Queso Blanco 500g', 3.40, 2.40],
    ['Huevos Carton x12', 2.90, 2.00],
    ['Pan Canilla', 1.40, 0.90],
    ['Jamon 250g', 2.70, 1.90],
    ['Mortadela 500g', 2.60, 1.80],
    ['Atun en Lata 170g', 2.10, 1.40],
    ['Sardinas en Lata', 1.60, 1.10],
    ['Mayonesa 400g', 2.20, 1.50],
    ['Salsa de Tomate 400g', 1.80, 1.20],
    ['Sal Refinada 1kg', 0.70, 0.40],
    ['Caraotas Negras 1kg', 1.50, 1.00],
    ['Lentejas 1kg', 1.50, 1.00],
    ['Arvejas 1kg', 1.45, 0.95],
    ['Maiz Dulce en Lata', 1.70, 1.20],
    ['Avena 400g', 1.60, 1.10],
    ['Cereal Corn Flakes 500g', 2.90, 2.00],
    ['Galletas Maria 500g', 1.90, 1.30],
    ['Chocolate en Polvo 400g', 2.95, 2.10],
    ['Refresco 2L', 2.00, 1.40],
    ['Agua Mineral 5L', 1.40, 0.90],
    ['Jugo de Naranja 1L', 2.40, 1.70],
    ['Malta 6-pack', 3.10, 2.20],
    ['Cerveza Lata', 1.40, 0.90],
    ['Papel Higienico x4', 2.20, 1.50],
    ['Jabon de Bano', 1.20, 0.80],
    ['Detergente 1kg', 3.20, 2.30],
    ['Cloro 1L', 1.10, 0.70],
    ['Suavizante 1L', 2.30, 1.60],
    ['Shampoo 400ml', 2.90, 2.00],
    ['Pasta Dental 100ml', 1.90, 1.30],
    ['Desodorante', 2.60, 1.80],
    ['Servilletas x100', 0.95, 0.60],
    ['Bolsas de Basura x10', 1.50, 1.00],
    ['Velas x6', 1.25, 0.80],
    ['Fosforos x10', 0.85, 0.50],
    ['Pilas AA x4', 1.80, 1.20],
    ['Bombillo LED 9W', 2.10, 1.40],
    ['Vinagre 1L', 1.35, 0.90],
    ['Salsa de Soja 250ml', 2.20, 1.50],
    ['Mermelada de Fresa 250g', 2.30, 1.60],
    ['Salami 250g', 2.45, 1.70],
];

$pg = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=inventory_balanzapro', 'postgres', 'GaboMac12', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "==> Tenant {$tenantId}, almacen {$warehouseId}\n";

$now = date('Y-m-d H:i:s');

// 1. Categoria Minimarket
$stmt = $pg->prepare('SELECT id FROM categories WHERE tenant_id = ? AND slug = ?');
$stmt->execute([$tenantId, 'minimarket']);
$categoryId = (int) $stmt->fetchColumn();
if ($categoryId <= 0) {
    $stmt = $pg->prepare('INSERT INTO categories (tenant_id, name, slug, is_active, created_at, updated_at) VALUES (?, ?, ?, true, ?, ?) RETURNING id');
    $stmt->execute([$tenantId, 'Minimarket', 'minimarket', $now, $now]);
    $categoryId = (int) $stmt->fetchColumn();
}
echo "==> Categoria Minimarket id={$categoryId}\n";

// 2. Listas de precio P3 (default), P1, P2
$priceLists = [];
foreach ([
    ['P3', 'Detal', true],
    ['P1', 'Mayor 1', false],
    ['P2', 'Mayor 2', false],
] as [$code, $name, $isDefault]) {
    $stmt = $pg->prepare('SELECT id FROM price_lists WHERE tenant_id = ? AND code = ?');
    $stmt->execute([$tenantId, $code]);
    $plId = (int) $stmt->fetchColumn();
    if ($plId <= 0) {
        $stmt = $pg->prepare('INSERT INTO price_lists (tenant_id, code, name, is_default, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, true, ?, ?) RETURNING id');
        $stmt->execute([$tenantId, $code, $name, $isDefault ? 'true' : 'false', $now, $now]);
        $plId = (int) $stmt->fetchColumn();
    }
    $priceLists[$code] = $plId;
}
echo '==> Listas P3='.$priceLists['P3'].' P1='.$priceLists['P1'].' P2='.$priceLists['P2']."\n";

// 3. Limpiar productos MIN-% previos
$stmt = $pg->prepare("SELECT id FROM products WHERE tenant_id = ? AND sku LIKE 'MIN-%'");
$stmt->execute([$tenantId]);
$oldIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
if ($oldIds !== []) {
    $in = implode(',', $oldIds);
    $pg->exec("DELETE FROM stock_balances WHERE tenant_id = {$tenantId} AND product_id IN ({$in})");
    $pg->exec("DELETE FROM product_prices WHERE tenant_id = {$tenantId} AND product_id IN ({$in})");
    $pg->exec("DELETE FROM product_category WHERE tenant_id = {$tenantId} AND product_id IN ({$in})");
    $pg->exec("DELETE FROM products WHERE tenant_id = {$tenantId} AND id IN ({$in})");
    echo '==> Eliminados '.count($oldIds)." productos MIN-% previos\n";
}

$insertProduct = $pg->prepare('
    INSERT INTO products (tenant_id, name, sku, barcode, is_active, tracking_type, base_price, sale_currency,
        unit_of_measure, track_stock, min_stock, max_stock, reorder_quantity, average_cost, last_purchase_cost,
        profit_margin, image_url, pricing_mode, created_at, updated_at)
    VALUES (?, ?, ?, ?, true, \'quantity\', ?, \'USD\', \'unit\', true, 5, 100, 10, ?, ?, ?, ?, \'manual\', ?, ?)
    RETURNING id
');
$insertStock = $pg->prepare('INSERT INTO stock_balances (tenant_id, warehouse_id, product_id, quantity_available, quantity_reserved, updated_at) VALUES (?, ?, ?, ?, 0, ?)');
$insertPrice = $pg->prepare('INSERT INTO product_prices (tenant_id, product_id, price_list_id, price, currency, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, \'USD\', true, ?, ?)');
$insertCat = $pg->prepare('INSERT INTO product_category (tenant_id, product_id, category_id) VALUES (?, ?, ?)');

$backgrounds = ['1e293b', '0f766e', '9f1239', 'b45309', '1d4ed8', '4338ca', '065f46', '7c2d12', '374151', 'be185d'];
$count = 0;
foreach ($items as $index => [$name, $price, $cost]) {
    $sku = sprintf('MIN-%04d', $index + 1);
    $barcode = '759'.str_pad((string) ($index + 1), 10, '0', STR_PAD_LEFT);
    $price = round((float) $price, 2);
    $cost = round((float) $cost, 2);
    $margin = $cost > 0 ? round((($price - $cost) / $cost) * 100, 2) : 0;
    $bg = $backgrounds[$index % count($backgrounds)];
    $text = rawurlencode(mb_substr($name, 0, 22));
    $imageUrl = "https://placehold.co/600x600/{$bg}/ffffff/png?text={$text}";

    $insertProduct->execute([$tenantId, $name, $sku, $barcode, $price, $cost, $cost, $margin, $imageUrl, $now, $now]);
    $productId = (int) $insertProduct->fetchColumn();

    $qty = 20 + (($index * 7) % 71); // 20..90 determinista
    $insertStock->execute([$tenantId, $warehouseId, $productId, $qty, $now]);

    $insertPrice->execute([$tenantId, $productId, $priceLists['P3'], $price, $now, $now]);
    $insertPrice->execute([$tenantId, $productId, $priceLists['P1'], round($price * 0.90, 2), $now, $now]);
    $insertPrice->execute([$tenantId, $productId, $priceLists['P2'], round($price * 0.95, 2), $now, $now]);

    $insertCat->execute([$tenantId, $productId, $categoryId]);

    $count++;
}

// 4. Tasa BCV activa (para mostrar Bs en el demo)
$stmt = $pg->prepare('SELECT id FROM exchange_rates WHERE tenant_id = ? AND exchange_rate_type_id = 1 AND is_active = true LIMIT 1');
$stmt->execute([$tenantId]);
if (! $stmt->fetchColumn()) {
    $pg->prepare('INSERT INTO exchange_rates (tenant_id, exchange_rate_type_id, base_currency, quote_currency, rate, effective_at, is_active, source, created_at, updated_at) VALUES (?, 1, \'USD\', \'VES\', 50.00, ?, true, \'demo\', ?, ?)')
        ->execute([$tenantId, $now, $now, $now]);
    echo "==> Tasa BCV 50.00 creada\n";
}

echo "==> Productos cargados: {$count}\n";
echo "LISTO.\n";
