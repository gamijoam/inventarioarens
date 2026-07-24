<?php

namespace App\Modules\DataImport\Support;

final class ImportStatus
{
    public const SESSION_PENDING = 'pending';

    public const SESSION_RUNNING = 'running';

    public const SESSION_COMPLETED = 'completed';

    public const SESSION_FAILED = 'failed';

    public const SESSION_CANCELLED = 'cancelled';

    public const ENTITY_PENDING = 'pending';

    public const ENTITY_RUNNING = 'running';

    public const ENTITY_COMPLETED = 'completed';

    public const ENTITY_FAILED = 'failed';

    public const ROW_OK = 'ok';

    public const ROW_SKIPPED = 'skipped';

    public const ROW_FAILED = 'failed';

    public const ENTITIES = [
        'branches',
        'warehouses',
        'brands',
        'categories',
        'tags',
        'products',
        'price_lists',
        'product_prices',
        'payment_methods',
        'customers',
        'suppliers',
    ];

    public static function sessionStatuses(): array
    {
        return [
            self::SESSION_PENDING,
            self::SESSION_RUNNING,
            self::SESSION_COMPLETED,
            self::SESSION_FAILED,
            self::SESSION_CANCELLED,
        ];
    }

    public static function isValidEntity(string $entity): bool
    {
        return in_array($entity, self::ENTITIES, true);
    }
}
