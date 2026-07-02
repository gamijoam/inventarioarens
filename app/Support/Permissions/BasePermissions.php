<?php

namespace App\Support\Permissions;

class BasePermissions
{
    public const PERMISSIONS = [
        'products.view',
        'products.create',
        'products.update',
        'products.delete',
        'inventory.view',
        'inventory.adjust',
        'inventory.transfer',
        'purchases.view',
        'purchases.create',
        'purchases.approve',
        'sales.view',
        'sales.create',
        'sales.cancel',
        'reports.view',
        'users.view',
        'users.create',
        'users.update',
        'users.delete',
        'settings.manage',
        'ai.use',
        'ai.configure',
    ];

    public const ROLE_PERMISSIONS = [
        'Owner' => self::PERMISSIONS,
        'Administrador' => self::PERMISSIONS,
        'Gerente' => [
            'products.view',
            'inventory.view',
            'purchases.view',
            'purchases.create',
            'sales.view',
            'sales.create',
            'reports.view',
            'users.view',
            'ai.use',
        ],
        'Vendedor' => [
            'products.view',
            'inventory.view',
            'sales.view',
            'sales.create',
            'ai.use',
        ],
        'Almacen' => [
            'products.view',
            'inventory.view',
            'inventory.adjust',
            'inventory.transfer',
            'purchases.view',
        ],
        'Auditor' => [
            'products.view',
            'inventory.view',
            'purchases.view',
            'sales.view',
            'reports.view',
            'users.view',
        ],
    ];
}
