<?php

declare(strict_types=1);

/**
 * Reemplaza las fotos placeholder de los productos MIN-% del tenant Demo (2)
 * por imagenes reales:
 *  - Busca en Open Food Facts (fotos reales de productos) con un keyword.
 *  - Si no hay resultado (ej. no-alimentos), usa picsum.photos (foto real).
 */

$tenantId = 2;
$ua = 'SistemaInventarioDemo/1.0 (demo@balanzapro.com)';

// Orden identico a scripts/seed_demo_minimarket.php (MIN-0001..MIN-0050)
$keywords = [
    'harina de maiz pan', 'arroz', 'pasta spaghetti', 'aceite vegetal', 'azucar refinada',
    'cafe molido', 'leche en polvo', 'leche liquida', 'mantequilla', 'queso blanco',
    'huevos', 'pan de molde', 'jamon', 'mortadela', 'atun en lata',
    'sardinas en lata', 'mayonesa', 'salsa de tomate', 'sal', 'caraotas negras',
    'lentejas', 'arvejas', 'maiz dulce en lata', 'avena', 'corn flakes',
    'galletas maria', 'chocolate en polvo', 'refresco cola', 'agua mineral', 'jugo de naranja',
    'malta', 'cerveza lata', 'papel higienico', 'jabon de bano', 'detergente',
    'cloro', 'suavizante ropa', 'shampoo', 'dentifrico', 'desodorante',
    'servilletas', 'bolsas', 'velas', 'fosforos', 'pilas',
    'bombillo', 'vinagre', 'salsa de soja', 'mermelada fresa', 'salami',
];

$pg = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=inventory_balanzapro', 'postgres', 'GaboMac12', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$stmt = $pg->prepare("SELECT id, sku, name, image_url FROM products WHERE tenant_id = ? AND sku LIKE 'MIN-%' ORDER BY sku");
$stmt->execute([$tenantId]);
$allProducts = $stmt->fetchAll();
// Solo reprocesar los que quedaron con picsum (reintento tras rate-limit).
$products = array_values(array_filter(
    $allProducts,
    static fn (array $p): bool => ! str_contains((string) $p['image_url'], 'openfoodfacts.org'),
));
echo 'Pendientes de reintento: '.count($products)."\n";

function searchOpenFoodFacts(string $keyword, string $ua): ?string
{
    $url = 'https://world.openfoodfacts.org/cgi/search.pl?search_terms='.urlencode($keyword)
        .'&search_simple=1&action=process&json=1&page_size=5&fields=product_name,image_url';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        return null;
    }

    $data = json_decode($body, true);
    if (! is_array($data) || ! isset($data['products']) || ! is_array($data['products'])) {
        return null;
    }

    $firstWord = strtolower(explode(' ', $keyword)[0]);
    $fallback = null;
    foreach ($data['products'] as $product) {
        $image = $product['image_url'] ?? null;
        if (! is_string($image) || $image === '') {
            continue;
        }
        $name = strtolower((string) ($product['product_name'] ?? ''));
        if ($firstWord !== '' && str_contains($name, $firstWord)) {
            return $image; // mejor coincidencia
        }
        $fallback ??= $image;
    }

    return $fallback;
}

$update = $pg->prepare('UPDATE products SET image_url = ?, updated_at = ? WHERE id = ? AND tenant_id = ?');
$now = date('Y-m-d H:i:s');
$offCount = 0;
$fallbackCount = 0;

foreach ($products as $product) {
    $index = ((int) substr($product['sku'], 4)) - 1;
    $keyword = $keywords[$index] ?? $product['name'];
    $image = null;
    for ($attempt = 0; $attempt < 3 && $image === null; $attempt++) {
        $image = searchOpenFoodFacts($keyword, $ua);
        if ($image === null) {
            sleep(3);
        }
    }

    if ($image !== null) {
        $offCount++;
        $source = 'OFF';
    } else {
        $image = 'https://picsum.photos/seed/'.$product['sku'].'/600/600';
        $fallbackCount++;
        $source = 'picsum';
    }

    $update->execute([$image, $now, $product['id'], $tenantId]);
    echo str_pad($product['sku'], 9).' ['.$source.'] '.$keyword."\n";

    usleep(1_200_000); // ser amable con la API
}

echo "\n==> OpenFoodFacts: {$offCount} | fallback picsum: {$fallbackCount} | total: ".count($products)."\n";
echo "LISTO.\n";
