<?php

namespace App\Modules\DataImport\Services;

use App\Modules\Branches\Models\Branch;
use App\Modules\DataImport\Support\ImportStatus;
use App\Modules\Products\Models\Brand;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;

class TemplateBuilder
{
    private const ENTITIES = [
        'branches' => [
            'headers' => ['code', 'name', 'status'],
            'examples' => [
                ['PRINCIPAL', 'Sucursal Principal', 'active'],
                ['NORTE', 'Sucursal Norte', 'active'],
            ],
            'tenant_values' => null,
        ],
        'warehouses' => [
            'headers' => ['code', 'name', 'branch_code', 'status'],
            'examples' => [
                ['PRINCIPAL', 'Almacen Principal', 'PRINCIPAL', 'active'],
            ],
            'tenant_values' => 'branches',
        ],
        'brands' => [
            'headers' => ['slug', 'name', 'description', 'is_active'],
            'examples' => [
                ['samsung', 'Samsung', 'Marca coreana', 'true'],
            ],
            'tenant_values' => null,
        ],
        'categories' => [
            'headers' => ['slug', 'name', 'parent_slug', 'description', 'sort_order', 'is_active'],
            'examples' => [
                ['electronica', 'Electronica', '', 'Productos electronicos', '0', 'true'],
                ['celulares', 'Celulares', 'electronica', '', '1', 'true'],
            ],
            'tenant_values' => null,
        ],
        'tags' => [
            'headers' => ['slug', 'name', 'color'],
            'examples' => [
                ['nuevo', 'Nuevo', '#00FF00'],
                ['oferta', 'Oferta', '#FF0000'],
            ],
            'tenant_values' => null,
        ],
        'products' => [
            'headers' => ['sku', 'name', 'barcode', 'description', 'brand_slug', 'category_slugs', 'tag_slugs', 'unit_of_measure', 'tracking_type', 'base_price', 'sale_currency', 'min_stock', 'max_stock', 'reorder_quantity', 'is_active', 'stock_inicial', 'almacen_codigo', 'costo_unitario'],
            'examples' => [
                ['SKU-001', 'Camisa Negra', '7501234567890', 'Camisa algodon talla M', 'samsung', 'electronica', 'nuevo', 'unit', 'quantity', '15.50', 'USD', '5', '100', '20', 'true', '', '', ''],
            ],
            'tenant_values' => ['brands', 'warehouses'],
        ],
        'customers' => [
            'headers' => ['document_type', 'document_number', 'name', 'phone', 'email', 'fiscal_address', 'is_active'],
            'examples' => [
                ['V', '12345678', 'Juan Perez', '+584141234567', 'juan@test.com', 'Av. Principal', 'true'],
                ['J', '12345678', 'Empresa X', '+584261234567', 'info@empresa.com', 'Calle 1', 'true'],
            ],
            'tenant_values' => null,
        ],
        'suppliers' => [
            'headers' => ['document_type', 'document_number', 'name', 'phone', 'email', 'fiscal_address', 'notes', 'is_active'],
            'examples' => [
                ['J', '12345678', 'Proveedor Mayor', '02121234567', 'ventas@prov.com', 'Centro', 'Proveedor principal', 'true'],
            ],
            'tenant_values' => null,
        ],
        'payment_methods' => [
            'headers' => ['code', 'name', 'method', 'currency_mode', 'requires_reference', 'is_active', 'sort_order'],
            'examples' => [
                ['CASH', 'Efectivo', 'cash', 'USD', 'false', 'true', '1'],
                ['ZELLE', 'Zelle', 'zelle', 'USD', 'true', 'true', '2'],
            ],
            'tenant_values' => null,
        ],
        'price_lists' => [
            'headers' => ['code', 'name', 'description', 'is_default', 'is_active', 'sort_order', 'payment_method_codes', 'prices'],
            'examples' => [
                ['MAYORISTA', 'Precios Mayorista', 'Lista para mayoristas', 'false', 'true', '1', 'CASH|PM', '[{"sku":"SKU-001","price":12.50,"currency":"USD"}]'],
            ],
            'tenant_values' => null,
        ],
    ];

    public function build(string $entity): string
    {
        if (! ImportStatus::isValidEntity($entity)) {
            throw new \InvalidArgumentException("Entidad invalida: {$entity}");
        }

        $cfg = self::ENTITIES[$entity];
        $tenantId = app(TenantManager::class)->require()->id;

        $rows = [];
        $rows[] = $cfg['headers'];

        foreach ($cfg['examples'] as $ex) {
            $rows[] = $ex;
        }

        if ($cfg['tenant_values']) {
            $tenantRows = $this->tenantValueRows($entity, $cfg['tenant_values'], $cfg['headers']);
            foreach ($tenantRows as $tr) {
                $rows[] = $tr;
            }
        }

        $separator = $entity === 'price_lists' ? ';' : ',';

        return $this->toCsv($rows, $separator);
    }

    private function tenantValueRows(string $entity, array|string $entities, array $headers): array
    {
        $rows = [];
        foreach ((array) $entities as $refEntity) {
            $data = $this->fetchReferenceValues($refEntity);
            foreach (array_slice($data, 0, 3) as $refRow) {
                $rows[] = $this->mapReferenceToTemplate($refEntity, $refRow, $headers);
            }
        }

        return $rows;
    }

    private function fetchReferenceValues(string $entity): array
    {
        return match ($entity) {
            'branches' => Branch::query()->limit(3)->get(['code', 'name'])->toArray(),
            'warehouses' => Warehouse::query()->limit(3)->get(['code', 'name'])->toArray(),
            'brands' => Brand::query()->limit(3)->get(['slug', 'name'])->toArray(),
            default => [],
        };
    }

    private function mapReferenceToTemplate(string $refEntity, array $refRow, array $headers): array
    {
        $row = array_fill(0, count($headers), '');
        foreach ($headers as $i => $h) {
            if ($h === 'brand_slug' && $refEntity === 'brands') {
                $row[$i] = $refRow['slug'] ?? '';
            } elseif ($h === 'category_slugs' && $refEntity === 'brands') {
                $row[$i] = '';
            } elseif ($h === 'almacen_codigo' && $refEntity === 'warehouses') {
                $row[$i] = $refRow['code'] ?? '';
            } elseif ($h === 'branch_code' && $refEntity === 'branches') {
                $row[$i] = $refRow['code'] ?? '';
            }
        }

        return $row;
    }

    private function toCsv(array $rows, string $separator): string
    {
        $buffer = fopen('php://temp', 'r+');
        if ($buffer === false) {
            return '';
        }

        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $cell) {
                $str = (string) $cell;
                if (str_contains($str, $separator) || str_contains($str, '"') || str_contains($str, "\n")) {
                    $line[] = '"'.str_replace('"', '""', $str).'"';
                } else {
                    $line[] = $str;
                }
            }
            fwrite($buffer, implode($separator, $line)."\n");
        }

        rewind($buffer);
        $content = stream_get_contents($buffer);
        fclose($buffer);

        return $content === false ? '' : $content;
    }
}
