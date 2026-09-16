<?php

namespace App\Console\Commands;

use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\PriceList;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportAsiamotoInventoryCommand extends Command
{
    protected $signature = 'import:asiamoto-inventory {--path= : Ruta al JSON procesado}';
    protected $description = 'Importa categorías, catálogo de productos y existencias para Comercial El Asiático (Tenant asiamoto)';

    public function handle(TenantManager $tenantManager): int
    {
        $jsonPath = $this->option('path') ?: storage_path('app/asiamoto_parsed_inventory.json');

        if (!file_exists($jsonPath)) {
            $this->error("No se encontró el archivo: {$jsonPath}");
            return self::FAILURE;
        }

        $this->info("Leyendo archivo {$jsonPath}...");
        $data = json_decode(file_get_contents($jsonPath), true);

        if (!$data || empty($data['products'])) {
            $this->error("El archivo JSON está vacío o no tiene productos.");
            return self::FAILURE;
        }

        $tenant = Tenant::find(4);
        if (!$tenant) {
            $this->error("No se encontró el Tenant ID 4 (asiamoto).");
            return self::FAILURE;
        }

        $tenant->update(['name' => 'COMERCIAL EL ASIATICO 88 C.A']);
        $tenantManager->set($tenant);

        $this->info("Iniciando importación para Tenant [{$tenant->id}] {$tenant->name}...");

        DB::beginTransaction();

        try {
            // 1. Moneda y Tasa de Cambio
            $rateType = ExchangeRateType::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => 'BCV'],
                ['name' => 'Banco Central de Venezuela', 'is_default' => true, 'is_active' => true]
            );

            ExchangeRate::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'exchange_rate_type_id' => $rateType->id, 'is_active' => true],
                [
                    'base_currency' => 'USD',
                    'quote_currency' => 'VES',
                    'rate' => 846.51,
                    'effective_at' => now(),
                    'source' => 'manual',
                ]
            );

            // 2. Sucursal y Almacén
            $branch = Branch::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => '001'],
                [
                    'name' => 'PRINCIPAL',
                    'slug' => 'principal',
                    'status' => 'active',
                ]
            );

            $warehouse = Warehouse::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => 'PRINCIPAL'],
                [
                    'branch_id' => $branch->id,
                    'name' => 'ALMACEN PRINCIPAL',
                    'status' => 'active',
                    'is_default' => true,
                ]
            );

            // 3. Listas de Precios
            $p3List = PriceList::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => 'P3'],
                ['name' => 'PVP Detal (Precio 3)', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]
            );

            $p1List = PriceList::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => 'P1'],
                ['name' => 'Precio Mayor 1 (Precio 1)', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]
            );

            $p2List = PriceList::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => 'P2'],
                ['name' => 'Precio Mayor 2 (Precio 2)', 'is_default' => false, 'is_active' => true, 'sort_order' => 3]
            );

            // 4. Categorías
            $categoryMap = [];
            foreach ($data['categories'] as $catData) {
                $cname = trim($catData['name']);
                $slug = Str::slug($cname);
                if (!$slug) {
                    $slug = 'cat-' . Str::random(6);
                }
                $category = Category::withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $cname],
                    ['slug' => $slug, 'is_active' => true]
                );
                $categoryMap[$cname] = $category->id;
            }

            if (!isset($categoryMap['Productos'])) {
                $defaultCat = Category::withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => 'Productos'],
                    ['slug' => 'productos', 'is_active' => true]
                );
                $categoryMap['Productos'] = $defaultCat->id;
            }

            $this->info("Categorías registradas: " . count($categoryMap));

            // 5. Productos, Precios y Stock
            $totalProducts = count($data['products']);
            $bar = $this->output->createProgressBar($totalProducts);
            $bar->start();

            $totalStockImported = 0;
            $totalCostImported = 0;

            foreach ($data['products'] as $item) {
                $sku = trim($item['sku']);
                $name = trim($item['name']);
                $cost = (float) $item['cost_price'];
                $stock = (float) $item['stock'];
                $p1 = (float) $item['price_1'];
                $p2 = (float) $item['price_2'];
                $p3 = (float) $item['price_3'];
                $catName = $item['category'] ?? 'Productos';
                $catId = $categoryMap[$catName] ?? $categoryMap['Productos'];

                // Base price: Prefer Precio 3, fallback a Precio 1, luego Costo
                $basePrice = $p3 > 0 ? $p3 : ($p1 > 0 ? $p1 : $cost);

                $product = Product::withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'sku' => $sku],
                    [
                        'name' => $name,
                        'base_price' => $basePrice,
                        'average_cost' => $cost,
                        'last_purchase_cost' => $cost,
                        'tracking_type' => Product::TRACKING_QUANTITY,
                        'track_stock' => true,
                        'sale_currency' => 'USD',
                        'sale_exchange_rate_type_id' => $rateType->id,
                        'is_active' => true,
                        'barcode' => $sku,
                    ]
                );

                // Asignar categoría
                DB::table('product_category')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'product_id' => $product->id],
                    ['category_id' => $catId]
                );

                // Listas de Precios
                if ($p3 > 0) {
                    DB::table('product_prices')->updateOrInsert(
                        ['tenant_id' => $tenant->id, 'product_id' => $product->id, 'price_list_id' => $p3List->id],
                        [
                            'price' => $p3,
                            'currency' => 'USD',
                            'exchange_rate_type_id' => $rateType->id,
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }

                if ($p1 > 0) {
                    DB::table('product_prices')->updateOrInsert(
                        ['tenant_id' => $tenant->id, 'product_id' => $product->id, 'price_list_id' => $p1List->id],
                        [
                            'price' => $p1,
                            'currency' => 'USD',
                            'exchange_rate_type_id' => $rateType->id,
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }

                if ($p2 > 0) {
                    DB::table('product_prices')->updateOrInsert(
                        ['tenant_id' => $tenant->id, 'product_id' => $product->id, 'price_list_id' => $p2List->id],
                        [
                            'price' => $p2,
                            'currency' => 'USD',
                            'exchange_rate_type_id' => $rateType->id,
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }

                // Balance y Movimiento de Inventario
                if ($stock > 0) {
                    DB::table('stock_balances')->updateOrInsert(
                        ['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id],
                        [
                            'quantity_available' => $stock,
                            'quantity_reserved' => 0,
                            'quantity_damaged' => 0,
                            'updated_at' => now(),
                        ]
                    );

                    DB::table('stock_movements')->updateOrInsert(
                        [
                            'tenant_id' => $tenant->id,
                            'warehouse_id' => $warehouse->id,
                            'product_id' => $product->id,
                            'reference_type' => 'initial_inventory',
                        ],
                        [
                            'type' => 'adjustment_in',
                            'quantity' => $stock,
                            'unit_cost' => $cost,
                            'reason' => 'Inventario inicial migración Saint',
                            'created_by' => 5, // Manuel
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );

                    $totalStockImported += $stock;
                    $totalCostImported += ($stock * $cost);
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            DB::commit();

            $this->info("Importación completada con éxito!");
            $this->table(
                ['Métrica', 'Valor'],
                [
                    ['Tenant', "{$tenant->id} - {$tenant->name} ({$tenant->slug})"],
                    ['Productos creados/actualizados', $totalProducts],
                    ['Categorías vinculadas', count($categoryMap)],
                    ['Sucursal / Almacén', "{$branch->name} / {$warehouse->name}"],
                    ['Existencia física importada', number_format($totalStockImported, 2, ',', '.') . ' unidades'],
                    ['Costo total inventario', '$' . number_format($totalCostImported, 2, ',', '.') . ' USD'],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("Error durante la importación: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return self::FAILURE;
        }
    }
}
