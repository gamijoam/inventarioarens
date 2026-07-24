<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\DataImport\Support\ImportStatus;
use InvalidArgumentException;

class ImporterRegistry
{
    /**
     * @var array<string, class-string<ImporterInterface>>
     */
    private const MAP = [
        'branches' => BranchImporter::class,
        'warehouses' => WarehouseImporter::class,
        'brands' => BrandImporter::class,
        'categories' => CategoryImporter::class,
        'tags' => TagImporter::class,
        'products' => ProductImporter::class,
        'price_lists' => PriceListImporter::class,
        'product_prices' => ProductPriceImporter::class,
        'payment_methods' => PaymentMethodImporter::class,
        'customers' => CustomerImporter::class,
        'suppliers' => SupplierImporter::class,
    ];

    public static function get(string $entity): ImporterInterface
    {
        if (! ImportStatus::isValidEntity($entity)) {
            throw new InvalidArgumentException("Entidad no soportada: {$entity}");
        }

        $class = self::MAP[$entity];

        return app($class);
    }

    /**
     * @return array<int, string>
     */
    public static function entities(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * @return array<string, class-string<ImporterInterface>>
     */
    public static function map(): array
    {
        return self::MAP;
    }
}
