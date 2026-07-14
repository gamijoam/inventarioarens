<?php

/**
 * Generate INVENTARIOARENS.postman_collection.json + .env.json from Laravel routes.
 *
 * Usage:
 *   php scripts/generate-postman.php
 *   php scripts/generate-postman.php --output-dir=C:\Users\gafit\Desktop\INVENTARIOARENS-Postman
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

$outputDir = __DIR__ . '/../storage/app/postman';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--output-dir=')) {
        $outputDir = substr($arg, strlen('--output-dir='));
    }
}
if (! is_dir($outputDir)) {
    @mkdir($outputDir, 0755, true);
}

$routes = Route::getRoutes();
$apiRoutes = [];
foreach ($routes as $route) {
    $uri = $route->uri();
    if (! str_starts_with($uri, 'api/')) {
        continue;
    }
    $methods = array_values(array_filter($route->methods(), fn ($m) => $m !== 'HEAD'));
    if (empty($methods)) {
        continue;
    }

    $apiRoutes[] = [
        'methods' => $methods,
        'uri' => $uri,
        'action' => $route->getActionName(),
        'middleware' => $route->middleware(),
        'name' => $route->getName(),
    ];
}

usort($apiRoutes, fn ($a, $b) => strcmp($a['uri'], $b['uri']));

echo 'API routes scanned: ' . count($apiRoutes) . PHP_EOL;

$moduleMap = [
    'Auth\\' => '1. Auth',
    'Bootstrap\\' => '0. Bootstrap',
    'AccessControl\\' => '2. AccessControl',
    'Tenancy\\' => '3. Tenancy',
    'Branches\\' => '4. Branches',
    'Warehouses\\' => '5. Warehouses',
    'Currency\\' => '6. Currency',
    'Products\\' => '7. Products',
    'Inventory\\' => '8. Inventory',
    'InventoryTransfers\\' => '9. InventoryTransfers',
    'InventoryTransferRequests\\' => '10. InventoryTransferRequests',
    'InventoryCenter\\' => '11. InventoryCenter',
    'Kardex\\' => '12. Kardex',
    'ProductEntries\\' => '13. ProductEntries',
    'ProductExits\\' => '14. ProductExits',
    'ProductEntries\\' => '13. ProductEntries',
    'Suppliers\\' => '15. Suppliers',
    'Purchases\\' => '16. Purchases',
    'PurchaseReturns\\' => '17. PurchaseReturns',
    'AccountsPayable\\' => '18. AccountsPayable',
    'AccountsReceivable\\' => '19. AccountsReceivable',
    'PaymentReceipts\\' => '20. PaymentReceipts',
    'FinancialAdjustments\\' => '21. FinancialAdjustments',
    'FinanceReports\\' => '22. FinanceReports',
    'PaymentMethods\\' => '23. PaymentMethods',
    'Customers\\' => '24. Customers',
    'Sales\\' => '25. Sales',
    'SalesReturns\\' => '26. SalesReturns',
    'POS\\' => '27. POS',
    'CashRegister\\' => '28. CashRegister',
    'Warranties\\' => '29. Warranties',
    'Reports\\' => '30. Reports',
    'Dashboard\\' => '31. Dashboard',
    'AdminPortal\\' => '32. AdminPortal',
    'Sync\\' => '33. Sync',
];

function moduleFolder(string $moduleKey): string
{
    return match ($moduleKey) {
        'Auth\\' => '1. Auth',
        'Bootstrap\\' => '0. Bootstrap',
        'AccessControl\\' => '2. AccessControl (Users/Roles/Permissions/Scopes)',
        'Tenancy\\' => '3. Tenancy (Master SaaS)',
        'Branches\\' => '4. Branches',
        'Warehouses\\' => '5. Warehouses',
        'Currency\\' => '6. Currency (Tasas de cambio)',
        'Products\\' => '7. Products & Prices',
        'Inventory\\' => '8. Inventory (Movimientos)',
        'InventoryTransfers\\' => '9. InventoryTransfers (Intra-tenant)',
        'InventoryTransferRequests\\' => '10. InventoryTransferRequests (Cross-tenant)',
        'InventoryCenter\\' => '11. InventoryCenter (Resumen ejecutivo)',
        'Kardex\\' => '12. Kardex',
        'ProductEntries\\' => '13. ProductEntries',
        'ProductExits\\' => '14. ProductExits',
        'Suppliers\\' => '15. Suppliers',
        'Purchases\\' => '16. Purchases (Ordenes de compra)',
        'PurchaseReturns\\' => '17. PurchaseReturns',
        'AccountsPayable\\' => '18. AccountsPayable (CxP)',
        'AccountsReceivable\\' => '19. AccountsReceivable (CxC)',
        'PaymentReceipts\\' => '20. PaymentReceipts',
        'FinancialAdjustments\\' => '21. FinancialAdjustments',
        'FinanceReports\\' => '22. FinanceReports',
        'PaymentMethods\\' => '23. PaymentMethods',
        'Customers\\' => '24. Customers',
        'Sales\\' => '25. Sales',
        'SalesReturns\\' => '26. SalesReturns',
        'POS\\' => '27. POS',
        'CashRegister\\' => '28. CashRegister',
        'Warranties\\' => '29. Warranties',
        'Reports\\' => '30. Reports',
        'Dashboard\\' => '31. Dashboard',
        'AdminPortal\\' => '32. AdminPortal (Portal administrativo)',
        'Sync\\' => '33. Sync (Local <-> Nube)',
        default => '99. Otros',
    };
}

function moduleFromAction(string $action): ?string
{
    if (! preg_match('#App\\\\Modules\\\\([^\\\\]+)\\\\Controllers\\\\#', $action, $m)) {
        return null;
    }
    return $m[1] . '\\';
}

function humanize(string $action): string
{
    if (! preg_match('#@(\w+)$#', $action, $m)) {
        return basename(str_replace('\\', '/', $action));
    }
    $method = $m[1];
    $parts = preg_split('/(?=[A-Z])/', $method, -1, PREG_SPLIT_NO_EMPTY);
    return strtoupper(implode(' ', $parts));
}

function exampleBodyFor(string $action, string $uri): array
{
    $method = strtolower((string) (preg_match('#@(\w+)$#', $action, $m) ? $m[1] : 'unknown'));

    if (str_contains($action, 'AuthController') && $method === 'login') {
        return [
            'email' => 'gerente.valencia@demo.test',
            'password' => 'password',
            'device_name' => 'postman',
        ];
    }
    if (str_contains($action, 'AuthController') && $method === 'platformlogin') {
        return [
            'email' => 'admin@saas.test',
            'password' => 'SecretPassword123!',
            'device_name' => 'postman',
        ];
    }
    if (str_contains($action, 'AuthController') && $method === 'tenants') {
        return ['email' => 'gerente.valencia@demo.test'];
    }
    if (str_contains($action, 'AuthController') && $method === 'switchtenant') {
        return ['tenant_slug' => 'demo-empresa', 'device_name' => 'postman'];
    }
    if (str_contains($action, 'BootstrapController') && $method === 'store') {
        return [
            'name' => 'SaaS Master',
            'email' => 'admin@saas.test',
            'password' => 'SecretPassword123!',
            'bootstrap_token' => '{{bootstrap_token}}',
            'tenant' => [
                'name' => 'Mi Empresa Inicial',
                'slug' => 'mi-empresa',
                'plan' => 'standard',
            ],
        ];
    }

    if (str_contains($action, 'TenantController') && $method === 'store') {
        return [
            'name' => 'Nueva Empresa',
            'slug' => 'nueva-empresa',
            'plan' => 'standard',
            'admin' => [
                'name' => 'Admin Local',
                'email' => 'admin@nueva.test',
                'password' => 'Secret123',
            ],
        ];
    }
    if (str_contains($action, 'CrossTenantUserController') && $method === 'store') {
        return [
            'user_id' => 1,
            'roles' => ['Administrador'],
        ];
    }

    if (str_contains($action, 'RoleController') && $method === 'store') {
        return [
            'name' => 'Cajero Senior',
            'permissions' => ['sales.view', 'pos.checkout', 'cash_register.open'],
        ];
    }
    if (str_contains($action, 'RoleController') && $method === 'updatepermissions') {
        return [
            'permissions' => ['sales.view', 'pos.view'],
        ];
    }
    if (str_contains($action, 'RoleController') && $method === 'duplicate') {
        return ['name' => 'Cajero Senior (copia)'];
    }
    if (str_contains($action, 'TenantUserController') && $method === 'store') {
        return [
            'name' => 'Juan Perez',
            'email' => 'juan@example.test',
            'password' => 'Secret123',
            'roles' => ['Vendedor'],
        ];
    }
    if (str_contains($action, 'TenantUserController') && $method === 'updatestatus') {
        return ['status' => 'active'];
    }
    if (str_contains($action, 'TenantUserController') && $method === 'updateroles') {
        return ['roles' => ['Vendedor', 'Almacen']];
    }

    if (str_contains($action, 'BranchController') && $method === 'store') {
        return ['name' => 'Sucursal Centro', 'code' => 'CENTRO'];
    }
    if (str_contains($action, 'BranchController') && $method === 'update') {
        return ['name' => 'Sucursal Centro Actualizada'];
    }

    if (str_contains($action, 'WarehouseController') && $method === 'store') {
        return ['name' => 'Almacen Principal', 'code' => 'PRINCIPAL', 'branch_id' => 1];
    }

    if (str_contains($action, 'ExchangeRateTypeController') && $method === 'store') {
        return ['code' => 'BCV', 'name' => 'Banco Central de Venezuela', 'is_default' => true];
    }
    if (str_contains($action, 'ExchangeRateController') && $method === 'store') {
        return [
            'exchange_rate_type_code' => 'BCV',
            'rate' => 36.50,
            'effective_at' => date('Y-m-d'),
        ];
    }

    if (str_contains($action, 'ProductController') && $method === 'store') {
        return [
            'sku' => 'SKU-NUEVO-001',
            'name' => 'Producto Demo',
            'tracking_type' => 'quantity',
            'base_price' => 10.00,
            'sale_currency' => 'USD',
            'sale_exchange_rate_type_code' => 'BCV',
            'is_active' => true,
        ];
    }
    if (str_contains($action, 'ProductController') && $method === 'update') {
        return ['name' => 'Producto Demo Actualizado', 'base_price' => 12.50];
    }
    if (str_contains($action, 'ProductController') && $method === 'syncprices') {
        return [
            'prices' => [
                ['price_list_code' => 'LISTA-STD', 'currency' => 'USD', 'amount' => 15.00],
            ],
        ];
    }

    if (str_contains($action, 'PriceListController') && $method === 'store') {
        return [
            'code' => 'LISTA-STD',
            'name' => 'Lista Estandar',
            'is_default' => true,
            'currency' => 'USD',
        ];
    }

    if (str_contains($action, 'PaymentMethodController') && $method === 'store') {
        return [
            'code' => 'EFECTIVO_USD',
            'name' => 'Efectivo USD',
            'method' => 'cash',
            'currency_mode' => 'USD',
            'requires_reference' => false,
            'sort_order' => 1,
        ];
    }

    if (str_contains($action, 'CustomerController') && $method === 'store') {
        return [
            'name' => 'Cliente Generico',
            'document_type' => 'V',
            'document_number' => '12345678',
            'phone' => '+584141234567',
            'email' => 'cliente@example.test',
            'is_generic' => false,
        ];
    }

    if (str_contains($action, 'SupplierController') && $method === 'store') {
        return [
            'name' => 'Proveedor Demo',
            'document_type' => 'J',
            'document_number' => '12345678-0',
            'phone' => '+584141234567',
            'email' => 'proveedor@example.test',
        ];
    }

    if (str_contains($action, 'PurchaseOrderController') && $method === 'store') {
        return [
            'supplier_id' => 1,
            'currency' => 'USD',
            'exchange_rate_type_code' => 'BCV',
            'items' => [
                ['product_id' => 1, 'warehouse_id' => 1, 'quantity' => 10, 'unit_cost' => 5.00],
            ],
        ];
    }
    if (str_contains($action, 'PurchaseOrderController') && $method === 'receive') {
        return [
            'items' => [
                ['purchase_item_id' => 1, 'quantity' => 10],
            ],
        ];
    }

    if (str_contains($action, 'SaleController') && $method === 'store') {
        return [
            'customer_id' => 1,
            'currency' => 'USD',
            'exchange_rate_type_code' => 'BCV',
            'items' => [
                ['product_id' => 1, 'warehouse_id' => 1, 'quantity' => 1, 'unit_price' => 10.00],
            ],
        ];
    }

    if (str_contains($action, 'PosOrderController') && $method === 'checkout') {
        return [
            'customer_id' => null,
            'cash_register_session_id' => 1,
            'currency' => 'USD',
            'exchange_rate_type_code' => 'BCV',
            'items' => [
                ['product_id' => 1, 'warehouse_id' => 1, 'quantity' => 1, 'unit_price' => 10.00],
            ],
            'payments' => [
                ['payment_method_id' => 1, 'currency' => 'USD', 'amount' => 10.00],
            ],
        ];
    }
    if (str_contains($action, 'PosOrderController') && $method === 'addpayments') {
        return [
            'payments' => [
                ['payment_method_id' => 1, 'currency' => 'USD', 'amount' => 5.00],
            ],
        ];
    }

    if (str_contains($action, 'CashRegisterSessionController') && $method === 'open') {
        return [
            'cash_register_id' => 1,
            'opening_amount' => 50.00,
            'currency' => 'USD',
            'exchange_rate_type_code' => 'BCV',
        ];
    }
    if (str_contains($action, 'CashRegisterSessionController') && $method === 'movement') {
        return [
            'type' => 'inflow',
            'method' => 'cash',
            'amount' => 20.00,
            'currency' => 'USD',
            'exchange_rate_type_code' => 'BCV',
            'reason' => 'Ingreso manual',
        ];
    }
    if (str_contains($action, 'CashRegisterSessionController') && $method === 'close') {
        return [
            'counted_amount' => 250.00,
            'currency' => 'USD',
            'exchange_rate_type_code' => 'BCV',
            'closing_notes' => 'Cierre OK',
        ];
    }
    if (str_contains($action, 'CashRegisterController') && $method === 'store') {
        return [
            'name' => 'Caja Principal',
            'code' => 'CAJA-01',
            'branch_id' => 1,
            'status' => 'active',
        ];
    }

    if (str_contains($action, 'InventoryTransferController') && $method === 'store') {
        return [
            'from_warehouse_id' => 1,
            'to_warehouse_id' => 2,
            'items' => [
                ['product_id' => 1, 'requested_quantity' => 5],
            ],
            'notes' => 'Traslado de prueba',
        ];
    }
    if (str_contains($action, 'InventoryTransferController') && $method === 'prepare') {
        return [
            'items' => [
                ['inventory_transfer_item_id' => 1, 'prepared_quantity' => 5],
            ],
        ];
    }
    if (str_contains($action, 'InventoryTransferController') && $method === 'receive') {
        return [
            'items' => [
                ['inventory_transfer_item_id' => 1, 'received_quantity' => 5],
            ],
        ];
    }

    if (str_contains($action, 'InventoryTransferRequestController') && $method === 'store') {
        return [
            'origin_tenant_slug' => 'origen',
            'destination_tenant_slug' => 'destino',
            'from_warehouse_id' => 1,
            'destination_warehouse_id' => 2,
            'items' => [
                ['origin_product_id' => 1, 'destination_product_id' => 2, 'quantity' => 5],
            ],
        ];
    }
    if (str_contains($action, 'InventoryTransferRequestController') && $method === 'accept') {
        return ['notes' => 'Aceptado'];
    }
    if (str_contains($action, 'InventoryTransferRequestController') && $method === 'reject') {
        return ['reason' => 'Sin stock suficiente'];
    }

    if (str_contains($action, 'ProductEntryController') && $method === 'store') {
        return [
            'reason' => 'Inventario inicial',
            'items' => [
                ['product_id' => 1, 'warehouse_id' => 1, 'quantity' => 10, 'unit_cost' => 5.00],
            ],
        ];
    }
    if (str_contains($action, 'ProductExitController') && $method === 'store') {
        return [
            'reason' => 'damaged',
            'items' => [
                ['product_id' => 1, 'warehouse_id' => 1, 'quantity' => 1],
            ],
        ];
    }

    if (str_contains($action, 'AccountsPayableController') && $method === 'pay') {
        return [
            'payment_method_id' => 1,
            'amount' => 50.00,
            'currency' => 'USD',
            'exchange_rate_type_code' => 'BCV',
        ];
    }
    if (str_contains($action, 'AccountsReceivableController') && $method === 'collect') {
        return [
            'payment_method_id' => 1,
            'amount' => 50.00,
            'currency' => 'USD',
            'exchange_rate_type_code' => 'BCV',
        ];
    }

    if (str_contains($action, 'WarrantyPolicyController') && $method === 'store') {
        return [
            'name' => 'Garantia Estandar 30 dias',
            'duration_days' => 30,
            'coverage_type' => 'store',
        ];
    }
    if (str_contains($action, 'WarrantyClaimController') && $method === 'store') {
        return [
            'sale_item_id' => 1,
            'customer_id' => 1,
            'issue_description' => 'Pantalla rota',
        ];
    }

    if (str_contains($action, 'SyncController') && $method === 'registernode') {
        return [
            'code' => 'LOCAL-PC-01',
            'name' => 'PC Mostrador',
            'type' => 'local',
            'status' => 'active',
            'branch_id' => 1,
            'metadata' => ['initial_snapshot' => false],
        ];
    }
    if (str_contains($action, 'SyncController') && $method === 'pushevents') {
        return [
            'origin_node_code' => 'LOCAL-PC-01',
            'events' => [
                [
                    'event_uuid' => '00000000-0000-0000-0000-000000000001',
                    'event_type' => 'product.created',
                    'aggregate_type' => 'product',
                    'aggregate_id' => 1,
                    'occurred_at' => date('c'),
                    'payload' => ['sku' => 'DEMO'],
                ],
            ],
        ];
    }
    if (str_contains($action, 'SyncController') && $method === 'acknowledge') {
        return ['node_code' => 'LOCAL-PC-01', 'status' => 'applied'];
    }
    if (str_contains($action, 'SyncController') && $method === 'issuetoken') {
        return ['name' => 'sync-worker', 'days' => 365];
    }
    if (str_contains($action, 'SyncController') && $method === 'markreadiness') {
        return [
            'installation_code' => 'LOCAL-PC-01',
            'node_code' => 'LOCAL-PC-01',
            'status' => 'ready',
        ];
    }

    if (str_contains($action, 'MasterController') && $method === 'storegroup') {
        return [
            'name' => 'Mi Grupo Empresarial',
            'slug' => 'mi-grupo',
            'plan' => 'standard',
            'owner' => [
                'name' => 'Owner del Grupo',
                'email' => 'owner@grupo.test',
                'password' => 'Secret123',
            ],
        ];
    }
    if (str_contains($action, 'MasterController') && $method === 'creategroupspinoff') {
        return [
            'name' => 'Empresa Spinoff',
            'slug' => 'empresa-spinoff',
            'plan' => 'standard',
        ];
    }
    if (str_contains($action, 'MasterController') && $method === 'updategroup') {
        return ['name' => 'Grupo Actualizado', 'plan' => 'enterprise'];
    }
    if (str_contains($action, 'PlatformAdminController') && $method === 'store') {
        return [
            'name' => 'Otro Platform Admin',
            'email' => 'otro@saas.test',
            'password' => 'Secret123',
        ];
    }
    if (str_contains($action, 'PlatformAdminController') && $method === 'update') {
        return ['name' => 'Platform Admin Actualizado'];
    }
    if (str_contains($action, 'PlatformAdminController') && $method === 'resetpassword') {
        return ['password' => 'NewSecret123'];
    }

    if (str_contains($action, 'ReplaceUserScope') || str_contains($action, 'replaceScope')) {
        return [
            'branch_ids' => [],
            'warehouse_ids' => [],
            'customer_group_ids' => [],
        ];
    }
    if (str_contains($action, 'ReplaceUserOverrides') || str_contains($action, 'replaceoverrides')) {
        return [
            'items' => [
                ['permission' => 'sales.cancel', 'effect' => 'deny'],
            ],
        ];
    }
    if (str_contains($action, 'UpdateRolePermissions') || str_contains($action, 'updatepermissions')) {
        return ['permissions' => ['sales.view', 'pos.view']];
    }

    return [];
}

function queryStringFor(string $uri): array
{
    $query = [];
    if (str_contains($uri, '{')) {
        $uri = preg_replace('/\{[^}]+\}/', '1', $uri);
    }
    if (str_contains($uri, 'stock/low')) {
        $query[] = ['key' => 'threshold', 'value' => '5', 'disabled' => false];
    }
    if (str_contains($uri, 'rates/current')) {
        $query[] = ['key' => 'rate_type_code', 'value' => 'BCV', 'disabled' => false];
    }
    if (str_contains($uri, 'events/pull')) {
        $query[] = ['key' => 'node_code', 'value' => 'LOCAL-PC-01', 'disabled' => false];
        $query[] = ['key' => 'limit', 'value' => '50', 'disabled' => false];
    }
    if (str_contains($uri, 'sync/status')) {
        $query[] = ['key' => 'node_code', 'value' => 'LOCAL-PC-01', 'disabled' => false];
    }
    if (str_contains($uri, 'sync/local-readiness') && ! str_ends_with($uri, '/local-readiness')) {
        $query[] = ['key' => 'installation_code', 'value' => 'LOCAL-PC-01', 'disabled' => false];
    }
    return $query;
}

function headersFor(array $middleware, string $uri): array
{
    $headers = [];
    $needsAuth = in_array('api.auth', $middleware, true);
    $needsTenant = in_array('tenant', $middleware, true);
    $isBootstrap = str_contains($uri, 'bootstrap');

    if ($isBootstrap) {
        $headers[] = ['key' => 'X-Bootstrap-Token', 'value' => '{{bootstrap_token}}', 'type' => 'text', 'disabled' => true];
        $headers[] = ['key' => 'Content-Type', 'value' => 'application/json', 'type' => 'text'];
    } else {
        if ($needsAuth) {
            $headers[] = ['key' => 'Authorization', 'value' => 'Bearer {{auth_token}}', 'type' => 'text'];
        }
        if ($needsTenant) {
            $headers[] = ['key' => 'X-Tenant', 'value' => '{{tenant_slug}}', 'type' => 'text'];
        }
        $headers[] = ['key' => 'Content-Type', 'value' => 'application/json', 'type' => 'text'];
    }
    return $headers;
}

$grouped = [];
foreach ($apiRoutes as $r) {
    $modKey = moduleFromAction($r['action']) ?? 'Otros\\';
    $grouped[$modKey][] = $r;
}

ksort($grouped);

$folders = [];
foreach ($grouped as $modKey => $routes) {
    $items = [];
    foreach ($routes as $r) {
        foreach ($r['methods'] as $method) {
            $uriClean = $r['uri'];
            $humanMethod = humanize($r['action']);
            $sample = exampleBodyFor($r['action'], $uriClean);

            $body = null;
            $bodyMode = 'raw';
            if (in_array($method, ['POST', 'PUT', 'PATCH']) && ! empty($sample)) {
                $body = [
                    'mode' => 'raw',
                    'raw' => json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'options' => ['raw' => ['language' => 'json']],
                ];
            } elseif (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                $body = ['mode' => 'raw', 'raw' => '{}', 'options' => ['raw' => ['language' => 'json']]];
            }

            $item = [
                'name' => str_pad($method, 6) . ' /' . substr($uriClean, 4),
                'request' => [
                    'method' => $method,
                    'header' => headersFor($r['middleware'], $uriClean),
                    'url' => [
                        'raw' => '{{base_url}}/' . substr($uriClean, 4) . (queryStringFor($uriClean) ? '?' . http_build_query(array_column(queryStringFor($uriClean), 'value', 'key')) : ''),
                        'host' => ['{{base_url}}'],
                        'path' => explode('/', substr($uriClean, 4)),
                    ],
                ],
            ];

            if (queryStringFor($uriClean)) {
                $item['request']['url']['query'] = queryStringFor($uriClean);
            }

            if ($body !== null) {
                $item['request']['body'] = $body;
            }

            $item['request']['description'] = sprintf(
                "Accion: %s\nMiddleware: %s\nModulo: %s",
                $r['action'],
                implode(', ', $r['middleware']),
                trim($modKey, '\\'),
            );

            $items[] = $item;
        }
    }

    $folders[] = [
        'name' => moduleFolder($modKey),
        'item' => $items,
    ];
}

usort($folders, function ($a, $b) {
    $numA = (int) (preg_match('/^(\d+)\./', $a['name'], $m) ? $m[1] : 999);
    $numB = (int) (preg_match('/^(\d+)\./', $b['name'], $m) ? $m[1] : 999);
    return $numA <=> $numB;
});

$collection = [
    'info' => [
        'name' => 'INVENTARIOARENS — API completa (regenerable)',
        '_postman_id' => Str::uuid()->toString(),
        'description' => 'Coleccion Postman auto-generada desde php artisan route:list.

Total: ' . count($apiRoutes) . ' endpoints en ' . count($folders) . ' carpetas.

Para regenerar:
  php scripts/generate-postman.php --output-dir=C:\Users\gafit\Desktop\INVENTARIOARENS-Postman

Docs backend: docs/BOOTSTRAP_API.md, docs/AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md',
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'auth' => [
        'type' => 'bearer',
        'bearer' => [['type' => 'string', 'key' => 'token', 'value' => '{{auth_token}}']],
    ],
    'variable' => [
        ['key' => 'base_url', 'value' => '{{base_url}}', 'type' => 'string'],
    ],
    'item' => $folders,
];

file_put_contents($outputDir . '/INVENTARIOARENS.postman_collection.json', json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo 'Wrote: ' . $outputDir . '/INVENTARIOARENS.postman_collection.json' . PHP_EOL;
echo 'Folders: ' . count($folders) . PHP_EOL;
echo 'Endpoints: ' . count($apiRoutes) . PHP_EOL;